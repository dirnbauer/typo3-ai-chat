<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Command;

use Closure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use Webconsulting\Typo3AiChat\Configuration\ExtensionConfiguration;
use Webconsulting\Typo3AiChat\Domain\Repository\ConversationRepository;
use Webconsulting\Typo3AiChat\Domain\Repository\MessageRepository;
use Webconsulting\Typo3AiChat\Enum\ConversationStatus;
use Webconsulting\Typo3AiChat\Service\AttachmentStorage;

/**
 * Retention and stuck-run recovery for chat conversations.
 *
 * Four passes, in the order they depend on each other:
 *
 * 1. **Unstick.** A turn runs inside a request, so a request that died — a PHP
 *    timeout, a closed connection, a fatal — leaves a conversation claimed
 *    forever. Nothing else can release that lock, which is why this pass exists
 *    and why it is first.
 * 2. **Archive** conversations nobody has touched for `autoArchiveDays`.
 * 3. **Delete** archived and soft-deleted conversations past
 *    `attachmentRetentionDays`, with their messages and their uploaded files.
 * 4. **Sweep** message rows whose conversation no longer exists.
 *
 * Files go before rows on purpose: a deleted row with orphaned files leaves
 * uploads nobody can find, while a deleted file with a surviving row is visible
 * and fixable.
 */
#[AsCommand(
    name: 'webconsulting-ai-chat:cleanup',
    description: 'Release stuck conversations and apply the configured chat retention',
)]
final class CleanupCommand extends Command
{
    /**
     * How long a claimed conversation may stay claimed before it is assumed
     * dead. Comfortably longer than any request PHP will allow to finish, so a
     * slow-but-alive turn is never stolen from under itself.
     */
    private const STUCK_TIMEOUT_SECONDS = 900;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly MessageRepository $messageRepository,
        private readonly AttachmentStorage $attachmentStorage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report what would happen without changing anything',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = $input->getOption('dry-run') === true;

        $unstuck = $this->releaseStuckConversations($dryRun);
        $archived = $this->archiveInactiveConversations($dryRun);
        [$deleted, $messages, $files] = $this->deleteExpiredConversations($dryRun);
        $orphans = $this->deleteOrphanedMessages($dryRun);

        $output->writeln($dryRun ? '<comment>Dry run — nothing was changed.</comment>' : '<info>Cleanup done.</info>');
        $output->writeln(sprintf('  Released stuck conversations:   %d', $unstuck));
        $output->writeln(sprintf('  Auto-archived conversations:    %d', $archived));
        $output->writeln(sprintf('  Deleted conversations:          %d', $deleted));
        $output->writeln(sprintf('  Deleted messages:               %d', $messages));
        $output->writeln(sprintf('  Deleted attachment files:       %d', $files));
        $output->writeln(sprintf('  Deleted orphaned messages:      %d', $orphans));

        return Command::SUCCESS;
    }

    private function releaseStuckConversations(bool $dryRun): int
    {
        $cutoff = time() - self::STUCK_TIMEOUT_SECONDS;
        $predicate = static fn(QueryBuilder $qb): array => [
            $qb->expr()->eq('status', $qb->createNamedParameter(ConversationStatus::Processing->value)),
            $qb->expr()->lt('tstamp', $qb->createNamedParameter($cutoff, Connection::PARAM_INT)),
            $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
        ];

        if ($dryRun) {
            return $this->countMatching(ConversationRepository::TABLE, $predicate);
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(ConversationRepository::TABLE);

        return (int)$queryBuilder
            ->update(ConversationRepository::TABLE)
            ->set('status', ConversationStatus::Failed->value)
            ->set('run_uuid', '')
            ->set('error_message', 'The turn did not finish. Its request ended before the run could settle.')
            ->where(...$predicate($queryBuilder))
            ->executeStatement();
    }

    private function archiveInactiveConversations(bool $dryRun): int
    {
        $days = $this->extensionConfiguration->getAutoArchiveDays();
        if ($days <= 0) {
            return 0;
        }

        $cutoff = time() - ($days * 86400);
        $predicate = static fn(QueryBuilder $qb): array => [
            $qb->expr()->eq('status', $qb->createNamedParameter(ConversationStatus::Idle->value)),
            $qb->expr()->eq('archived', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            $qb->expr()->lt('tstamp', $qb->createNamedParameter($cutoff, Connection::PARAM_INT)),
        ];

        if ($dryRun) {
            return $this->countMatching(ConversationRepository::TABLE, $predicate);
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(ConversationRepository::TABLE);

        return (int)$queryBuilder
            ->update(ConversationRepository::TABLE)
            ->set('archived', 1)
            ->where(...$predicate($queryBuilder))
            ->executeStatement();
    }

    /**
     * @return array{0: int, 1: int, 2: int} conversations, messages, files
     */
    private function deleteExpiredConversations(bool $dryRun): array
    {
        $days = $this->extensionConfiguration->getAttachmentRetentionDays();
        if ($days <= 0) {
            return [0, 0, 0];
        }

        $cutoff = time() - ($days * 86400);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(ConversationRepository::TABLE);
        $rows = $queryBuilder->select('uid', 'be_user')
            ->from(ConversationRepository::TABLE)
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('archived', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                ),
                $queryBuilder->expr()->lt('tstamp', $queryBuilder->createNamedParameter($cutoff, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $uids = [];
        $owners = [];
        foreach ($rows as $row) {
            $uid = is_numeric($row['uid'] ?? null) ? (int)$row['uid'] : 0;
            if ($uid <= 0) {
                continue;
            }
            $uids[] = $uid;
            $owners[$uid] = is_numeric($row['be_user'] ?? null) ? (int)$row['be_user'] : 0;
        }

        if ($uids === []) {
            return [0, 0, 0];
        }

        if ($dryRun) {
            return [count($uids), $this->countMessagesOf($uids), 0];
        }

        $files = 0;
        foreach ($uids as $uid) {
            $files += $this->attachmentStorage->deleteConversationFiles($owners[$uid], $uid);
        }

        $messages = $this->messageRepository->deleteByConversations($uids);

        $delete = $this->connectionPool->getQueryBuilderForTable(ConversationRepository::TABLE);
        $deleted = (int)$delete->delete(ConversationRepository::TABLE)
            ->where(
                $delete->expr()->in('uid', $delete->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
            )
            ->executeStatement();

        return [$deleted, $messages, $files];
    }

    /**
     * Message rows whose conversation is gone — from a hard delete somebody did
     * in the List module, or from an interrupted run of this command.
     */
    private function deleteOrphanedMessages(bool $dryRun): int
    {
        $conversations = $this->connectionPool->getQueryBuilderForTable(ConversationRepository::TABLE);
        $existing = $conversations->select('uid')
            ->from(ConversationRepository::TABLE)
            ->executeQuery()
            ->fetchFirstColumn();

        /** @var list<int> $existingUids */
        $existingUids = array_values(array_map(intval(...), array_filter($existing, is_numeric(...))));

        $predicate = static fn(QueryBuilder $qb): array => $existingUids === []
            ? [$qb->expr()->gt('conversation', $qb->createNamedParameter(-1, Connection::PARAM_INT))]
            : [$qb->expr()->notIn(
                'conversation',
                $qb->createNamedParameter($existingUids, Connection::PARAM_INT_ARRAY),
            )];

        if ($dryRun) {
            return $this->countMatching(MessageRepository::TABLE, $predicate);
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(MessageRepository::TABLE);

        return (int)$queryBuilder->delete(MessageRepository::TABLE)
            ->where(...$predicate($queryBuilder))
            ->executeStatement();
    }

    /**
     * @param Closure(QueryBuilder): list<string> $predicate
     */
    private function countMatching(string $table, Closure $predicate): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $count = $queryBuilder->count('uid')
            ->from($table)
            ->where(...$predicate($queryBuilder))
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int)$count : 0;
    }

    /**
     * @param list<int> $uids
     */
    private function countMessagesOf(array $uids): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(MessageRepository::TABLE);
        $count = $queryBuilder->count('uid')
            ->from(MessageRepository::TABLE)
            ->where(
                $queryBuilder->expr()->in('conversation', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int)$count : 0;
    }
}

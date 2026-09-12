<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Upgrades;

use Doctrine\DBAL\Exception as DbalException;
use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Upgrades\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface;
use Webconsulting\Typo3AiChat\Domain\Repository\ConversationRepository;
use Webconsulting\Typo3AiChat\Domain\Repository\MessageRepository;
use Webconsulting\Typo3AiChat\Enum\MessageRole;

/**
 * Moves a 1.x conversation's `messages` JSON column into
 * `tx_webconsultingaichat_message` rows.
 *
 * 1.x kept the whole transcript in one mediumtext blob, which meant every
 * append rewrote every message, a single malformed entry cost the conversation
 * all of them, and nothing could be queried. 2.0 stores one row per message.
 *
 * The wizard reads the OLD column directly rather than through the model,
 * because the model no longer knows about it — and it must keep working on a
 * database where `ext_tables.sql` has already dropped nothing (TYPO3 never
 * drops a column on its own, so the data is still there when this runs).
 *
 * Idempotent: a conversation that already has message rows is skipped, so a
 * re-run after a partial failure resumes rather than duplicating.
 */
#[UpgradeWizard('webconsultingAiChat_messagesToRows')]
final readonly class MessagesToRowsUpgradeWizard implements UpgradeWizardInterface
{
    private const LEGACY_COLUMN = 'messages';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'AI Chat: migrate conversation transcripts to message rows';
    }

    public function getDescription(): string
    {
        return 'TYPO3 AI Chat 2.0 stores each chat message as its own row in '
            . 'tx_webconsultingaichat_message instead of a JSON blob on the conversation. '
            . 'This wizard converts existing transcripts. It can be run repeatedly: '
            . 'conversations that already have message rows are left alone. The legacy '
            . '"messages" column is emptied but not dropped — remove it with the database '
            . 'analyser once you are satisfied with the result.';
    }

    public function getPrerequisites(): array
    {
        return [DatabaseUpdatedPrerequisite::class];
    }

    public function updateNecessary(): bool
    {
        if (!$this->legacyColumnExists()) {
            return false;
        }

        return $this->legacyRows(1) !== [];
    }

    public function executeUpdate(): bool
    {
        if (!$this->legacyColumnExists()) {
            return true;
        }

        foreach ($this->legacyRows(0) as $row) {
            $this->migrateConversation($row);
        }

        return true;
    }

    /**
     * @param array{uid: int, messages: string} $row
     */
    private function migrateConversation(array $row): void
    {
        $conversationUid = $row['uid'];
        $messages = $this->decode($row['messages']);

        if ($messages !== [] && $this->messageCount($conversationUid) === 0) {
            $this->insertMessages($conversationUid, $messages);
        }

        $this->clearLegacyColumn($conversationUid, $this->messageCount($conversationUid));
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function insertMessages(int $conversationUid, array $messages): void
    {
        $connection = $this->connectionPool->getConnectionForTable(MessageRepository::TABLE);
        $sequence = 0;
        $lastCreatedAt = 0;

        foreach ($messages as $message) {
            $role = MessageRole::tryFrom($this->stringValue($message, 'role'));
            if ($role === null) {
                // A role this version does not know cannot be replayed to a
                // provider, and guessing would put words in somebody's mouth.
                continue;
            }

            ++$sequence;
            $createdAt = $this->timestamp($message);
            $lastCreatedAt = max($lastCreatedAt, $createdAt);

            $connection->insert(MessageRepository::TABLE, [
                'pid' => 0,
                'conversation' => $conversationUid,
                'sequence' => $sequence,
                'role' => $role->value,
                'content' => $this->content($message),
                'tool_calls' => $this->jsonColumn($message, 'tool_calls'),
                'tool_call_id' => mb_substr($this->stringValue($message, 'tool_call_id'), 0, 64),
                'attachments' => $this->jsonColumn($message, 'attachments'),
                'run_uuid' => '',
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'crdate' => $createdAt,
            ]);
        }

        if ($lastCreatedAt > 0) {
            $this->connectionPool->getConnectionForTable(ConversationRepository::TABLE)->update(
                ConversationRepository::TABLE,
                ['last_message_at' => $lastCreatedAt],
                ['uid' => $conversationUid],
            );
        }
    }

    private function clearLegacyColumn(int $conversationUid, int $messageCount): void
    {
        $this->connectionPool->getConnectionForTable(ConversationRepository::TABLE)->update(
            ConversationRepository::TABLE,
            [self::LEGACY_COLUMN => '', 'message_count' => $messageCount],
            ['uid' => $conversationUid],
        );
    }

    /**
     * @return list<array{uid: int, messages: string}>
     */
    private function legacyRows(int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(ConversationRepository::TABLE);
        $queryBuilder->select('uid', self::LEGACY_COLUMN)
            ->from(ConversationRepository::TABLE)
            ->where(
                $queryBuilder->expr()->isNotNull(self::LEGACY_COLUMN),
                $queryBuilder->expr()->neq(
                    self::LEGACY_COLUMN,
                    $queryBuilder->createNamedParameter('', Connection::PARAM_STR),
                ),
            )
            ->orderBy('uid', 'ASC');

        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }

        $rows = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $uid = $row['uid'] ?? null;
            $messages = $row[self::LEGACY_COLUMN] ?? null;
            if (is_numeric($uid) && is_string($messages)) {
                $rows[] = ['uid' => (int)$uid, 'messages' => $messages];
            }
        }

        return $rows;
    }

    private function messageCount(int $conversationUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(MessageRepository::TABLE);
        $count = $queryBuilder->count('uid')
            ->from(MessageRepository::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'conversation',
                    $queryBuilder->createNamedParameter($conversationUid, Connection::PARAM_INT),
                ),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int)$count : 0;
    }

    /**
     * The column is gone on a fresh 2.0 install, and asking the schema is the
     * only honest way to tell "nothing to migrate" from "cannot migrate".
     */
    private function legacyColumnExists(): bool
    {
        try {
            $columns = $this->connectionPool
                ->getConnectionForTable(ConversationRepository::TABLE)
                ->createSchemaManager()
                ->listTableColumns(ConversationRepository::TABLE);
        } catch (DbalException) {
            return false;
        }

        foreach (array_keys($columns) as $name) {
            if (strtolower((string)$name) === self::LEGACY_COLUMN) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $messages = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $message = [];
            foreach ($item as $key => $value) {
                if (is_string($key)) {
                    $message[$key] = $value;
                }
            }
            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * 1.x allowed the content to be a structured array (an attachment-carrying
     * user message). A row holds text, so anything non-scalar is preserved as
     * its JSON rather than discarded.
     *
     * @param array<string, mixed> $message
     */
    private function content(array $message): string
    {
        $content = $message['content'] ?? '';
        if (is_string($content)) {
            return $content;
        }
        if (is_scalar($content)) {
            return (string)$content;
        }

        $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json) ? $json : '';
    }

    /**
     * @param array<string, mixed> $message
     */
    private function jsonColumn(array $message, string $key): ?string
    {
        $value = $message[$key] ?? null;
        if (!is_array($value) || $value === []) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json) ? $json : null;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function timestamp(array $message): int
    {
        $createdAt = $message['createdAt'] ?? null;
        if (is_numeric($createdAt)) {
            return (int)$createdAt;
        }
        if (is_string($createdAt) && $createdAt !== '') {
            $parsed = strtotime($createdAt);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function stringValue(array $message, string $key): string
    {
        $value = $message[$key] ?? '';

        return is_scalar($value) ? (string)$value : '';
    }
}

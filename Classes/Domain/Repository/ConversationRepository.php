<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\Typo3AiChat\Domain\Model\Conversation;
use Webconsulting\Typo3AiChat\Enum\ConversationStatus;

/**
 * DBAL-based repository — no Extbase, direct QueryBuilder access.
 *
 * Every read a user can reach is scoped by `be_user`, so a guessed uid never
 * opens somebody else's conversation.
 */
readonly class ConversationRepository
{
    public const TABLE = 'tx_webconsultingaichat_conversation';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function findByUid(int $uid): ?Conversation
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? Conversation::fromRow($row) : null;
    }

    public function findOneByUidAndBeUser(int $uid, int $beUserUid): ?Conversation
    {
        if ($uid <= 0 || $beUserUid <= 0) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('be_user', $queryBuilder->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? Conversation::fromRow($row) : null;
    }

    /**
     * @return list<Conversation>
     */
    public function findByBeUser(int $beUserUid, bool $includeArchived = false): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('be_user', $queryBuilder->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('pinned', 'DESC')
            ->addOrderBy('last_message_at', 'DESC')
            ->addOrderBy('uid', 'DESC');

        if (!$includeArchived) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('archived', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            );
        }

        return array_map(Conversation::fromRow(...), $queryBuilder->executeQuery()->fetchAllAssociative());
    }

    public function add(Conversation $conversation): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $data = $conversation->toRow();
        $data['pid'] = 0;
        $data['crdate'] = $data['tstamp'] = time();
        $connection->insert(self::TABLE, $data);

        return (int)$connection->lastInsertId();
    }

    public function update(Conversation $conversation): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $data = $conversation->toRow();
        $data['tstamp'] = time();
        $connection->update(self::TABLE, $data, ['uid' => $conversation->getUid()]);
    }

    /**
     * Claim the conversation for one turn.
     *
     * This is the single-in-flight-turn lock, and it is a compare-and-swap for
     * the reason every lock is: two tabs pressing send at the same moment must
     * not both start a run against the same transcript. The loser is told the
     * conversation is busy rather than silently interleaving with the winner.
     *
     * @param list<ConversationStatus> $expected the states a turn may start from
     */
    public function claimForTurn(int $uid, int $beUserUid, array $expected, string $runUuid): bool
    {
        if ($expected === []) {
            return false;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $expectedValues = array_map(static fn(ConversationStatus $status): string => $status->value, $expected);
        $placeholders = implode(', ', array_fill(0, count($expectedValues), '?'));

        $affected = $connection->executeStatement(
            'UPDATE ' . self::TABLE
            . ' SET status = ?, run_uuid = ?, error_message = ?, pending_approval = ?, tstamp = ?'
            . ' WHERE uid = ? AND be_user = ? AND deleted = 0 AND status IN (' . $placeholders . ')',
            [
                ConversationStatus::Processing->value,
                $runUuid,
                '',
                '',
                time(),
                $uid,
                $beUserUid,
                ...$expectedValues,
            ],
        );

        return $affected > 0;
    }

    /**
     * Release the claim, settling the conversation into its post-turn state.
     *
     * @param array<string, mixed> $pendingApproval
     */
    public function settle(
        int $uid,
        int $beUserUid,
        ConversationStatus $status,
        string $errorMessage = '',
        array $pendingApproval = [],
        string $runUuid = '',
    ): void {
        $encoded = '';
        if ($pendingApproval !== []) {
            $json = json_encode($pendingApproval, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $encoded = is_string($json) ? $json : '';
        }

        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'status' => $status->value,
                'error_message' => $errorMessage,
                'pending_approval' => $encoded,
                'run_uuid' => $runUuid,
                'tstamp' => time(),
            ],
            ['uid' => $uid, 'be_user' => $beUserUid],
        );
    }

    /**
     * Write back the counters a finished turn produced, without touching the
     * lifecycle columns.
     */
    public function touchMessages(int $uid, int $messageCount, int $lastMessageAt, ?string $title = null): void
    {
        $data = [
            'message_count' => $messageCount,
            'last_message_at' => $lastMessageAt,
            'tstamp' => time(),
        ];
        if ($title !== null && $title !== '') {
            $data['title'] = mb_substr($title, 0, 255);
        }

        $this->connectionPool->getConnectionForTable(self::TABLE)->update(self::TABLE, $data, ['uid' => $uid]);
    }

    public function countActiveByBeUser(int $beUserUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $count = $queryBuilder->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('be_user', $queryBuilder->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('status', $queryBuilder->createNamedParameter(
                    [ConversationStatus::Processing->value, ConversationStatus::AwaitingApproval->value],
                    Connection::PARAM_STR_ARRAY,
                )),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int)$count : 0;
    }

    public function updateArchived(int $uid, bool $archived, int $beUserUid): void
    {
        $this->updateColumns($uid, $beUserUid, ['archived' => (int)$archived]);
    }

    public function updatePinned(int $uid, bool $pinned, int $beUserUid): void
    {
        $this->updateColumns($uid, $beUserUid, ['pinned' => (int)$pinned]);
    }

    public function updateTitle(int $uid, string $title, int $beUserUid): void
    {
        $this->updateColumns($uid, $beUserUid, ['title' => mb_substr(trim($title), 0, 255)]);
    }

    public function updateAutoApproveTools(int $uid, bool $autoApprove, int $beUserUid): void
    {
        $this->updateColumns($uid, $beUserUid, ['auto_approve_tools' => (int)$autoApprove]);
    }

    /**
     * Soft-delete. The row survives for the cleanup command, which prunes it
     * together with its messages and its attachments.
     */
    public function softDelete(int $uid, int $beUserUid): void
    {
        $this->updateColumns($uid, $beUserUid, ['deleted' => 1]);
    }

    /**
     * @param array<string, int|string> $data
     */
    private function updateColumns(int $uid, int $beUserUid, array $data): void
    {
        $data['tstamp'] = time();
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, $data, ['uid' => $uid, 'be_user' => $beUserUid]);
    }
}

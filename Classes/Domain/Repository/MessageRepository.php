<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\Typo3AiChat\Domain\Model\Message;

/**
 * Transcript rows, one per message.
 *
 * The sequence is assigned here rather than by the caller, and the table's
 * UNIQUE (conversation, sequence) is what makes that safe: two writers racing
 * for the same number cannot both win, so a gap or a duplicate is impossible
 * even though the number is chosen with a separate SELECT.
 */
readonly class MessageRepository
{
    public const TABLE = 'tx_webconsultingaichat_message';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<Message>
     */
    public function findByConversation(int $conversationUid, int $afterSequence = 0, int $limit = 0): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('conversation', $queryBuilder->createNamedParameter($conversationUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('sequence', $queryBuilder->createNamedParameter($afterSequence, Connection::PARAM_INT)),
            )
            ->orderBy('sequence', 'ASC');

        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }

        return array_map(Message::fromRow(...), $queryBuilder->executeQuery()->fetchAllAssociative());
    }

    /**
     * The tail of a transcript, oldest-first.
     *
     * Used to build what the model sees: a conversation grows without bound but
     * a context window does not, so only the last N messages are replayed.
     *
     * @return list<Message>
     */
    public function findTail(int $conversationUid, int $limit): array
    {
        if ($limit <= 0) {
            return $this->findByConversation($conversationUid);
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('conversation', $queryBuilder->createNamedParameter($conversationUid, Connection::PARAM_INT)),
            )
            ->orderBy('sequence', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(Message::fromRow(...), array_reverse($rows));
    }

    /**
     * Append one message and return it with its assigned uid and sequence.
     */
    public function append(Message $message): Message
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $sequence = $this->nextSequence($message->conversation);
        $crdate = time();

        $row = $message->toRow();
        $row['sequence'] = $sequence;
        $row['crdate'] = $crdate;
        $connection->insert(self::TABLE, $row);

        return new Message(
            uid: (int)$connection->lastInsertId(),
            conversation: $message->conversation,
            sequence: $sequence,
            role: $message->role,
            content: $message->content,
            toolCalls: $message->toolCalls,
            toolCallId: $message->toolCallId,
            attachments: $message->attachments,
            runUuid: $message->runUuid,
            promptTokens: $message->promptTokens,
            completionTokens: $message->completionTokens,
            crdate: $crdate,
        );
    }

    public function countByConversation(int $conversationUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $count = $queryBuilder->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('conversation', $queryBuilder->createNamedParameter($conversationUid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int)$count : 0;
    }

    /**
     * @param list<int> $conversationUids
     */
    public function deleteByConversations(array $conversationUids): int
    {
        if ($conversationUids === []) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return (int)$queryBuilder->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->in(
                    'conversation',
                    $queryBuilder->createNamedParameter($conversationUids, Connection::PARAM_INT_ARRAY),
                ),
            )
            ->executeStatement();
    }

    public function nextSequence(int $conversationUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $max = $queryBuilder->selectLiteral('MAX(' . $queryBuilder->quoteIdentifier('sequence') . ')')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('conversation', $queryBuilder->createNamedParameter($conversationUid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return (is_numeric($max) ? (int)$max : 0) + 1;
    }
}

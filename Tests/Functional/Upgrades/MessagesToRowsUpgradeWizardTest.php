<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Functional\Upgrades;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\Typo3AiChat\Domain\Repository\ConversationRepository;
use Webconsulting\Typo3AiChat\Domain\Repository\MessageRepository;
use Webconsulting\Typo3AiChat\Enum\MessageRole;
use Webconsulting\Typo3AiChat\Tests\Functional\AbstractChatFunctionalTestCase;
use Webconsulting\Typo3AiChat\Upgrades\MessagesToRowsUpgradeWizard;

/**
 * Turning a 1.x transcript blob into rows.
 *
 * A migration is only ever run once per installation and usually without
 * anybody watching, so the properties that matter are the unglamorous ones:
 * it converts faithfully, it can be re-run after an interruption without
 * duplicating, and it never destroys the source before it has produced the
 * replacement.
 */
final class MessagesToRowsUpgradeWizardTest extends AbstractChatFunctionalTestCase
{
    private const LEGACY_TRANSCRIPT = [
        ['role' => 'user', 'content' => 'What is on page 1?', 'createdAt' => '2026-01-01T10:00:00+00:00'],
        [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [[
                'id' => 'call-1',
                'type' => 'function',
                'function' => ['name' => 'GetPage', 'arguments' => '{"uid":1}'],
            ]],
            'createdAt' => '2026-01-01T10:00:01+00:00',
        ],
        [
            'role' => 'tool',
            'content' => '{"title":"Home"}',
            'tool_call_id' => 'call-1',
            'createdAt' => '2026-01-01T10:00:02+00:00',
        ],
        ['role' => 'assistant', 'content' => 'Page 1 is "Home".', 'createdAt' => '2026-01-01T10:00:03+00:00'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_webconsultingaichat_conversation.csv');
        $this->addLegacyColumn();
    }

    #[Test]
    public function aLegacyTranscriptBecomesRowsInOrder(): void
    {
        $this->givenLegacyMessages(1, self::LEGACY_TRANSCRIPT);

        self::assertTrue($this->wizard()->updateNecessary());
        self::assertTrue($this->wizard()->executeUpdate());

        $messages = $this->get(MessageRepository::class)->findByConversation(1);

        self::assertCount(4, $messages);
        self::assertSame([1, 2, 3, 4], array_map(static fn(object $m): int => $m->sequence, $messages));
        self::assertSame(MessageRole::User, $messages[0]->role);
        self::assertSame('What is on page 1?', $messages[0]->content);

        self::assertSame(MessageRole::Assistant, $messages[1]->role);
        self::assertSame('call-1', $messages[1]->toolCalls[0]['id'] ?? null);

        self::assertSame(MessageRole::Tool, $messages[2]->role);
        self::assertSame('call-1', $messages[2]->toolCallId, 'The round-trip must still replay after migration.');

        self::assertSame('Page 1 is "Home".', $messages[3]->content);
    }

    #[Test]
    public function theConversationCountersCatchUpWithItsRows(): void
    {
        $this->givenLegacyMessages(1, self::LEGACY_TRANSCRIPT);

        $this->wizard()->executeUpdate();

        $conversation = $this->get(ConversationRepository::class)->findByUid(1);
        self::assertNotNull($conversation);
        self::assertSame(4, $conversation->getMessageCount());
        self::assertSame(
            strtotime('2026-01-01T10:00:03+00:00'),
            $conversation->getLastMessageAt(),
            'The last message time comes from the transcript, not from the migration run.',
        );
    }

    #[Test]
    public function aSecondRunAfterAnInterruptionDoesNotDuplicate(): void
    {
        $this->givenLegacyMessages(1, self::LEGACY_TRANSCRIPT);

        $this->wizard()->executeUpdate();
        // Put the blob back, as an interrupted run would have left it.
        $this->givenLegacyMessages(1, self::LEGACY_TRANSCRIPT);
        $this->wizard()->executeUpdate();

        self::assertSame(4, $this->get(MessageRepository::class)->countByConversation(1));
    }

    #[Test]
    public function aRoleThisVersionDoesNotKnowIsSkippedRatherThanGuessed(): void
    {
        $this->givenLegacyMessages(1, [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'function', 'content' => 'from some older format'],
            ['role' => 'assistant', 'content' => 'Hi'],
        ]);

        $this->wizard()->executeUpdate();

        $messages = $this->get(MessageRepository::class)->findByConversation(1);
        self::assertCount(2, $messages);
        self::assertSame(['user', 'assistant'], array_map(static fn(object $m): string => $m->role->value, $messages));
    }

    #[Test]
    public function thereIsNothingToDoOnAFreshInstallation(): void
    {
        self::assertFalse(
            $this->wizard()->updateNecessary(),
            'A 2.0 install has an empty legacy column and must not be asked to migrate.',
        );
    }

    #[Test]
    public function theLegacyColumnIsEmptiedButNotDropped(): void
    {
        $this->givenLegacyMessages(1, self::LEGACY_TRANSCRIPT);

        $this->wizard()->executeUpdate();

        $queryBuilder = $this->get(ConnectionPool::class)
            ->getQueryBuilderForTable(ConversationRepository::TABLE);
        $value = $queryBuilder->select('messages')
            ->from(ConversationRepository::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        self::assertSame('', $value, 'Emptied, so a re-run finds nothing...');
        self::assertFalse($this->wizard()->updateNecessary(), '...and the wizard reports itself done.');
    }

    // ---------------------------------------------------------------- helpers

    private function wizard(): MessagesToRowsUpgradeWizard
    {
        return new MessagesToRowsUpgradeWizard($this->get(ConnectionPool::class));
    }

    /**
     * 2.0's schema has no `messages` column, so an upgrade scenario has to put
     * one back — exactly as it exists on a site that has not yet run the
     * database analyser's DROP.
     */
    private function addLegacyColumn(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable(ConversationRepository::TABLE);
        $connection->executeStatement(
            'ALTER TABLE ' . ConversationRepository::TABLE . ' ADD COLUMN messages mediumtext',
        );
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function givenLegacyMessages(int $conversationUid, array $messages): void
    {
        $this->get(ConnectionPool::class)
            ->getConnectionForTable(ConversationRepository::TABLE)
            ->update(
                ConversationRepository::TABLE,
                ['messages' => json_encode($messages, JSON_THROW_ON_ERROR)],
                ['uid' => $conversationUid],
            );
    }
}

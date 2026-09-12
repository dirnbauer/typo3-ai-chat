<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use Webconsulting\Typo3AiChat\Controller\ChatApiController;
use Webconsulting\Typo3AiChat\Domain\Repository\ConversationRepository;
use Webconsulting\Typo3AiChat\Domain\Repository\MessageRepository;
use Webconsulting\Typo3AiChat\Enum\ConversationStatus;
use Webconsulting\Typo3AiChat\Enum\MessageRole;
use Webconsulting\Typo3AiChat\Testing\ScriptedProvider;
use Webconsulting\Typo3AiChat\Tests\Functional\AbstractChatFunctionalTestCase;
use Webconsulting\Typo3AiChat\Tests\Functional\DecodesApiResponses;

/**
 * A whole turn, from the HTTP body to the persisted rows.
 *
 * The scripted provider makes the model deterministic, so what is actually
 * under test is everything around it: the projection offering a real MCP tool,
 * the loop calling it, the ambient-identity check letting it through, and the
 * round-trip landing in the transcript in a shape the NEXT turn can replay.
 */
final class ChatTurnTest extends AbstractChatFunctionalTestCase
{
    use DecodesApiResponses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_webconsultingaichat_conversation.csv');
    }

    #[Test]
    public function aPlainAnswerIsPersistedAndReported(): void
    {
        ScriptedProvider::script([
            ['content' => 'TYPO3 is a content management system.'],
        ]);

        $body = $this->decodeJson($this->turn('What is TYPO3?'));

        self::assertSame('completed', $body['outcome']);
        self::assertSame(ConversationStatus::Idle->value, $body['status']);

        $messages = $this->messages();
        self::assertCount(2, $messages, 'The question and the answer.');
        self::assertSame(MessageRole::User, $messages[0]->role);
        self::assertSame('What is TYPO3?', $messages[0]->content);
        self::assertSame(MessageRole::Assistant, $messages[1]->role);
        self::assertSame('TYPO3 is a content management system.', $messages[1]->content);
    }

    #[Test]
    public function aScriptedReadToolCallIsExecutedAndItsResultStreamed(): void
    {
        ScriptedProvider::script([
            ['toolCalls' => [['id' => 'call-1', 'name' => 'typo3_GetPage', 'arguments' => ['uid' => 1]]]],
            ['content' => 'That page does not exist yet.'],
        ]);

        $body = $this->decodeJson($this->turn('What is on page 1?'));

        self::assertSame('completed', $body['outcome']);

        $events = $this->eventNames($body);
        self::assertContains('run.started', $events);
        self::assertContains('step.tool.call', $events);
        self::assertContains('step.tool.result', $events);
        self::assertContains('message.final', $events);
        self::assertContains('run.finished', $events);

        $call = $this->eventPayload($body, 'step.tool.call');
        self::assertSame('typo3_GetPage', $call['name']);
        self::assertSame('read_only', $call['effect'], 'The client needs to know a read is a read.');

        $result = $this->eventPayload($body, 'step.tool.result');
        self::assertSame('call-1', $result['callId'], 'A result must be attributable to the call it answers.');
        self::assertArrayHasKey('preview', $result);
    }

    /**
     * The transcript has to replay, and a provider rejects a tool turn whose
     * assistant tool-call turn is missing — so both halves must be stored.
     */
    #[Test]
    public function aToolRoundTripIsPersistedAsBothHalves(): void
    {
        ScriptedProvider::script([
            ['toolCalls' => [['id' => 'call-1', 'name' => 'typo3_GetPage', 'arguments' => ['uid' => 1]]]],
            ['content' => 'Done.'],
        ]);

        $this->turn('What is on page 1?');

        $roles = array_map(
            static fn(object $m): string => $m->role->value,
            $this->messages(),
        );

        self::assertSame(['user', 'assistant', 'tool', 'assistant'], $roles);

        $messages = $this->messages();
        self::assertNotSame([], $messages[1]->toolCalls, 'The assistant turn carries the call it requested.');
        self::assertSame('call-1', $messages[2]->toolCallId, 'The tool turn answers it by id.');
    }

    #[Test]
    public function theConversationIsCountedAndTimestampedAfterTheTurn(): void
    {
        ScriptedProvider::script([['content' => 'Hello.']]);

        $this->turn('Hi');

        $conversation = $this->get(ConversationRepository::class)->findByUid(1);
        self::assertNotNull($conversation);
        self::assertSame(2, $conversation->getMessageCount());
        self::assertGreaterThan(0, $conversation->getLastMessageAt());
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
    }

    #[Test]
    public function anEmptyMessageIsRefusedBeforeAnythingIsSpent(): void
    {
        $response = $this->turn('   ');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $this->get(MessageRepository::class)->countByConversation(1));
    }

    #[Test]
    public function aSecondTurnIsRefusedWhileTheFirstStillHoldsTheConversation(): void
    {
        $this->get(ConversationRepository::class)->claimForTurn(
            1,
            self::BE_USER_UID,
            [ConversationStatus::Idle],
            'someone-elses-run',
        );

        $response = $this->turn('Anybody there?');

        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function anotherUsersConversationIsNotFound(): void
    {
        ScriptedProvider::script([['content' => 'should never run']]);

        $response = $this->turn('Hello', conversation: 2);

        self::assertSame(404, $response->getStatusCode(), 'Not 403 — a stranger learns nothing about what exists.');
    }

    // ---------------------------------------------------------------- helpers

    private function turn(string $content, int $conversation = 1): ResponseInterface
    {
        $request = (new ServerRequest('https://example.com/typo3/ajax/webconsulting/ai-chat/conversations/turn', 'POST'))
            ->withParsedBody(['conversation' => $conversation, 'content' => $content]);

        $controller = $this->get(ChatApiController::class);
        self::assertInstanceOf(ChatApiController::class, $controller);

        return $controller->turn($request);
    }

    /**
     * @return list<\Webconsulting\Typo3AiChat\Domain\Model\Message>
     */
    private function messages(int $conversation = 1): array
    {
        return $this->get(MessageRepository::class)->findByConversation($conversation);
    }
}

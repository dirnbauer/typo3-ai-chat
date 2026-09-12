<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Functional\Controller;

use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use Webconsulting\Typo3AiChat\Controller\ChatApiController;
use Webconsulting\Typo3AiChat\Domain\Repository\ConversationRepository;
use Webconsulting\Typo3AiChat\Domain\Repository\MessageRepository;
use Webconsulting\Typo3AiChat\Enum\ConversationStatus;
use Webconsulting\Typo3AiChat\Testing\ScriptedProvider;
use Webconsulting\Typo3AiChat\Tests\Functional\AbstractChatFunctionalTestCase;
use Webconsulting\Typo3AiChat\Tests\Functional\DecodesApiResponses;

/**
 * The pause a write tool causes, and the two ways out of it.
 *
 * This is the extension's central safety property: the model may ASK to change
 * the installation, and a human decides. A regression here would not look like
 * a bug — it would look like the chat getting more helpful.
 */
final class ChatApprovalTest extends AbstractChatFunctionalTestCase
{
    use DecodesApiResponses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tx_webconsultingaichat_conversation.csv');

        // A write tool is dark until an administrator switches it on — which is
        // the behaviour under test in the projection suite, and a precondition
        // here.
        $this->get(ToolStateRepository::class)->setEnabled('typo3_WriteTable', true);
    }

    #[Test]
    public function aWriteToolSuspendsTheTurnInsteadOfRunning(): void
    {
        ScriptedProvider::script([
            ['toolCalls' => [[
                'id' => 'call-1',
                'name' => 'typo3_WriteTable',
                'arguments' => ['table' => 'pages', 'action' => 'update', 'uid' => 1, 'data' => ['title' => 'New']],
            ]]],
        ]);

        $body = $this->decodeJson($this->turn('Rename page 1 to "New".'));

        self::assertSame('awaiting_approval', $body['outcome']);
        self::assertSame(ConversationStatus::AwaitingApproval->value, $body['status']);

        self::assertNotSame(
            '',
            $this->stringAt($body, 'pendingApproval', 'turnDigest'),
            'The digest is what binds a decision to this turn.',
        );
        $calls = $this->valueAt($body, 'pendingApproval', 'calls');
        self::assertIsArray($calls);
        self::assertIsArray($calls[0]);
        self::assertSame('typo3_WriteTable', $calls[0]['name']);

        $conversation = $this->get(ConversationRepository::class)->findByUid(1);
        self::assertNotNull($conversation);
        self::assertSame(ConversationStatus::AwaitingApproval, $conversation->getStatus());
        self::assertNotSame('', $conversation->getRunUuid(), 'The run must stay addressable for the decision.');
    }

    #[Test]
    public function approvingRunsTheCallAndFinishesTheTurn(): void
    {
        ScriptedProvider::script([
            ['toolCalls' => [[
                'id' => 'call-1',
                'name' => 'typo3_WriteTable',
                'arguments' => ['table' => 'pages', 'action' => 'update', 'uid' => 1, 'data' => ['title' => 'New']],
            ]]],
            ['content' => 'I renamed the page.'],
        ]);

        $suspended = $this->decodeJson($this->turn('Rename page 1 to "New".'));
        $digest = $this->stringAt($suspended, 'pendingApproval', 'turnDigest');

        $body = $this->decodeJson($this->decide(true, $digest));

        self::assertSame('completed', $body['outcome']);
        self::assertSame(ConversationStatus::Idle->value, $body['status']);

        $events = $this->eventNames($body);
        self::assertContains('step.tool.result', $events, 'The approved call actually ran.');

        $conversation = $this->get(ConversationRepository::class)->findByUid(1);
        self::assertNotNull($conversation);
        self::assertSame(ConversationStatus::Idle, $conversation->getStatus());
        self::assertSame([], $conversation->getPendingApproval(), 'A decided turn stops asking.');
    }

    #[Test]
    public function denyingRefusesTheCallIntoTheTranscriptAndStillFinishes(): void
    {
        ScriptedProvider::script([
            ['toolCalls' => [[
                'id' => 'call-1',
                'name' => 'typo3_WriteTable',
                'arguments' => ['table' => 'pages', 'action' => 'update', 'uid' => 1, 'data' => ['title' => 'New']],
            ]]],
            ['content' => 'Understood, I left the page alone.'],
        ]);

        $suspended = $this->decodeJson($this->turn('Rename page 1 to "New".'));
        $digest = $this->stringAt($suspended, 'pendingApproval', 'turnDigest');

        $body = $this->decodeJson($this->decide(false, $digest));

        self::assertSame('completed', $body['outcome']);

        $toolMessages = array_values(array_filter(
            $this->get(MessageRepository::class)->findByConversation(1),
            static fn(object $m): bool => $m->role->value === 'tool',
        ));
        self::assertNotSame([], $toolMessages, 'A refusal is part of the transcript, not a silence.');
        self::assertStringContainsString('denied', strtolower($toolMessages[0]->content));
    }

    /**
     * The stale-tab case ADR-132 exists for: a decision that does not name the
     * turn it decided must not authorise calls nobody reviewed.
     */
    #[Test]
    public function anApprovalWithoutADigestIsRefused(): void
    {
        ScriptedProvider::script([
            ['toolCalls' => [[
                'id' => 'call-1',
                'name' => 'typo3_WriteTable',
                'arguments' => ['table' => 'pages', 'action' => 'update', 'uid' => 1, 'data' => ['title' => 'New']],
            ]]],
        ]);

        $this->turn('Rename page 1 to "New".');

        $response = $this->decide(true, '');

        self::assertSame(409, $response->getStatusCode());

        $conversation = $this->get(ConversationRepository::class)->findByUid(1);
        self::assertNotNull($conversation);
        self::assertSame(
            ConversationStatus::AwaitingApproval,
            $conversation->getStatus(),
            'A refused decision leaves the turn waiting, not broken.',
        );
    }

    #[Test]
    public function anApprovalForAConversationThatIsNotWaitingIsRefused(): void
    {
        $response = $this->decide(true, 'whatever');

        self::assertSame(409, $response->getStatusCode());
    }

    // ---------------------------------------------------------------- helpers

    private function turn(string $content): ResponseInterface
    {
        $request = (new ServerRequest('https://example.com/typo3/ajax/webconsulting/ai-chat/conversations/turn', 'POST'))
            ->withParsedBody(['conversation' => 1, 'content' => $content]);

        return $this->controller()->turn($request);
    }

    private function decide(bool $approved, string $digest): ResponseInterface
    {
        $request = (new ServerRequest('https://example.com/typo3/ajax/webconsulting/ai-chat/conversations/approval', 'POST'))
            ->withParsedBody(['conversation' => 1, 'approved' => $approved, 'turnDigest' => $digest]);

        return $this->controller()->approval($request);
    }

    private function controller(): ChatApiController
    {
        $controller = $this->get(ChatApiController::class);
        self::assertInstanceOf(ChatApiController::class, $controller);

        return $controller;
    }

}

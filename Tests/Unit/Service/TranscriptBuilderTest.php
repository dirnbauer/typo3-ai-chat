<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Typo3AiChat\Domain\Model\Message;
use Webconsulting\Typo3AiChat\Enum\MessageRole;
use Webconsulting\Typo3AiChat\Service\TranscriptBuilder;

/**
 * The builder turns stored rows into the message list a provider is sent, and
 * the failure it exists to prevent is not a wrong answer — it is a REJECTED
 * request. A tool turn whose assistant tool-call turn fell out of the window is
 * an answer to a question that was never asked, and OpenAI refuses the whole
 * call.
 */
final class TranscriptBuilderTest extends TestCase
{
    #[Test]
    public function theSystemLayerGoesGeneralBeforeSpecific(): void
    {
        $transcript = (new TranscriptBuilder())->build(
            [$this->user('Hello')],
            'You are a TYPO3 assistant.',
            'Always answer in German.',
            'The user is currently looking at page uid 42.',
        );

        self::assertCount(4, $transcript);
        self::assertSame('system', $transcript[0]->role);
        self::assertSame('You are a TYPO3 assistant.', $transcript[0]->content);
        self::assertSame('Always answer in German.', $transcript[1]->content);
        self::assertSame('The user is currently looking at page uid 42.', $transcript[2]->content);
        self::assertSame('user', $transcript[3]->role);
    }

    #[Test]
    public function emptySystemLayersAreOmittedEntirely(): void
    {
        $transcript = (new TranscriptBuilder())->build([$this->user('Hello')], '', '   ', '');

        self::assertCount(1, $transcript);
        self::assertSame('user', $transcript[0]->role);
    }

    #[Test]
    public function aToolRoundTripSurvivesAsAPair(): void
    {
        $transcript = (new TranscriptBuilder())->build([
            $this->user('What is on page 42?'),
            $this->assistantCalling('call-1', 'typo3_GetPage', ['uid' => 42]),
            $this->toolResult('call-1', '{"title":"Home"}'),
            $this->assistant('Page 42 is called Home.'),
        ]);

        self::assertCount(4, $transcript);
        self::assertSame('assistant', $transcript[1]->role);
        self::assertNotNull($transcript[1]->toolCalls);
        self::assertSame('typo3_GetPage', $transcript[1]->toolCalls[0]->name);
        self::assertSame('tool', $transcript[2]->role);
        self::assertSame('call-1', $transcript[2]->toolCallId);
    }

    /**
     * The one that matters: a window boundary that cuts a round-trip in half.
     */
    #[Test]
    public function anOrphanedToolTurnIsDroppedRatherThanSentAlone(): void
    {
        $messages = [
            $this->assistantCalling('call-1', 'typo3_GetPage', ['uid' => 42]),
            $this->toolResult('call-1', '{"title":"Home"}'),
            $this->user('And page 43?'),
            $this->assistant('Let me look.'),
        ];

        // A window of three cuts the assistant tool-call turn off the front and
        // leaves its answer behind.
        $transcript = (new TranscriptBuilder())->build($messages, window: 3);

        foreach ($transcript as $message) {
            self::assertNotSame('tool', $message->role, 'A tool turn without its call must not be sent.');
        }
        self::assertCount(2, $transcript);
    }

    #[Test]
    public function aToolTurnWhoseCallIsStillInTheWindowSurvivesTheCut(): void
    {
        $messages = [
            $this->user('old'),
            $this->user('older'),
            $this->assistantCalling('call-1', 'typo3_GetPage', ['uid' => 42]),
            $this->toolResult('call-1', '{"title":"Home"}'),
        ];

        $transcript = (new TranscriptBuilder())->build($messages, window: 2);

        self::assertCount(2, $transcript);
        self::assertSame('assistant', $transcript[0]->role);
        self::assertSame('tool', $transcript[1]->role);
    }

    #[Test]
    public function attachmentsAreNamedInTheTextBecauseAModelCannotOpenAFalUid(): void
    {
        $message = new Message(
            uid: 1,
            conversation: 1,
            sequence: 1,
            role: MessageRole::User,
            content: 'Summarise this.',
            attachments: [['fileUid' => 9, 'fileName' => 'report.pdf']],
        );

        $transcript = (new TranscriptBuilder())->build([$message]);

        self::assertStringContainsString('Summarise this.', $transcript[0]->content);
        self::assertStringContainsString('[Attached: report.pdf]', $transcript[0]->content);
    }

    #[Test]
    public function anEmptyAssistantTurnWithNoToolCallsIsNotReplayed(): void
    {
        $transcript = (new TranscriptBuilder())->build([
            $this->user('Hello'),
            $this->assistant(''),
        ]);

        self::assertCount(1, $transcript);
    }

    #[Test]
    public function theWindowKeepsTheTailNotTheHead(): void
    {
        $messages = [];
        for ($i = 1; $i <= 10; ++$i) {
            $messages[] = $this->user('message ' . $i);
        }

        $transcript = (new TranscriptBuilder())->build($messages, window: 3);

        self::assertCount(3, $transcript);
        self::assertSame('message 8', $transcript[0]->content);
        self::assertSame('message 10', $transcript[2]->content);
    }

    private function user(string $content): Message
    {
        return new Message(0, 1, 0, MessageRole::User, $content);
    }

    private function assistant(string $content): Message
    {
        return new Message(0, 1, 0, MessageRole::Assistant, $content);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function assistantCalling(string $callId, string $name, array $arguments): Message
    {
        return new Message(
            uid: 0,
            conversation: 1,
            sequence: 0,
            role: MessageRole::Assistant,
            content: '',
            toolCalls: [ToolCall::function($callId, $name, $arguments)->toArray()],
        );
    }

    private function toolResult(string $callId, string $content): Message
    {
        return new Message(
            uid: 0,
            conversation: 1,
            sequence: 0,
            role: MessageRole::Tool,
            content: $content,
            toolCallId: $callId,
        );
    }

    /**
     * Guards the assumption every other test here rests on.
     */
    #[Test]
    public function theBuilderProducesRealChatMessages(): void
    {
        $transcript = (new TranscriptBuilder())->build([$this->user('Hello')]);

        self::assertInstanceOf(ChatMessage::class, $transcript[0]);
    }
}

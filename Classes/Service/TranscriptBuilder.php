<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Service;

use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Webconsulting\Typo3AiChat\Domain\Model\Message;
use Webconsulting\Typo3AiChat\Enum\MessageRole;

/**
 * Turns persisted message rows into the message list one agent run is given.
 *
 * Two things make this more than a map():
 *
 * **The window.** A conversation grows without bound and a context window does
 * not, so only the tail is replayed. That is a truncation, and a truncation in
 * the middle of a tool round-trip produces a transcript no provider will
 * accept: a `tool` turn whose matching assistant tool-call turn fell off the
 * front is an answer to a question that was never asked. OpenAI rejects it
 * outright. So orphaned tool turns are dropped from the front of the window
 * until the first surviving message stands on its own.
 *
 * **The system layer.** The task's own prompt, the conversation's prompt and
 * the "where the user is standing" snippet are three different authors of the
 * same thing. They are emitted as separate system messages in that order —
 * general before specific — so the more specific one wins where they disagree.
 */
final readonly class TranscriptBuilder
{
    /**
     * How many stored messages may be replayed. A ceiling, not a budget: the
     * provider's own context accounting is what actually bounds a request, and
     * nr-llm does that accounting. This only stops an unbounded row count from
     * being loaded and serialised in the first place.
     */
    public const DEFAULT_WINDOW = 60;

    /**
     * @param list<Message> $messages the conversation's rows, oldest first
     *
     * @return list<ChatMessage>
     */
    public function build(
        array $messages,
        string $taskPrompt = '',
        string $conversationPrompt = '',
        string $contextSnippet = '',
        int $window = self::DEFAULT_WINDOW,
    ): array {
        $transcript = [];

        foreach ([$taskPrompt, $conversationPrompt, $contextSnippet] as $systemText) {
            $systemText = trim($systemText);
            if ($systemText !== '') {
                $transcript[] = ChatMessage::system($systemText);
            }
        }

        foreach ($this->window($messages, $window) as $message) {
            $chatMessage = $this->toChatMessage($message);
            if ($chatMessage !== null) {
                $transcript[] = $chatMessage;
            }
        }

        return $transcript;
    }

    /**
     * The replayable tail: the last `$window` rows, with any leading tool turn
     * whose assistant tool-call turn is no longer present dropped.
     *
     * @param list<Message> $messages
     *
     * @return list<Message>
     */
    public function window(array $messages, int $window = self::DEFAULT_WINDOW): array
    {
        $tail = $window > 0 && count($messages) > $window
            ? array_slice($messages, -$window)
            : $messages;

        $known = [];
        $result = [];
        foreach ($tail as $message) {
            if ($message->role === MessageRole::Assistant) {
                foreach ($message->toolCalls as $call) {
                    $id = $call['id'] ?? null;
                    if (is_string($id) && $id !== '') {
                        $known[$id] = true;
                    }
                }
            }

            if ($message->role === MessageRole::Tool && !isset($known[$message->toolCallId])) {
                continue;
            }

            $result[] = $message;
        }

        return $result;
    }

    private function toChatMessage(Message $message): ?ChatMessage
    {
        return match ($message->role) {
            MessageRole::System => $message->content === '' ? null : ChatMessage::system($message->content),
            MessageRole::User => ChatMessage::user($this->userContent($message)),
            MessageRole::Assistant => $this->assistantMessage($message),
            MessageRole::Tool => $message->toolCallId === ''
                ? null
                : ChatMessage::toolResult($message->toolCallId, $message->content),
        };
    }

    private function assistantMessage(Message $message): ?ChatMessage
    {
        $toolCalls = $this->toolCalls($message);
        if ($toolCalls !== []) {
            return ChatMessage::assistantToolCalls($toolCalls, $message->content);
        }

        return $message->content === '' ? null : ChatMessage::assistant($message->content);
    }

    /**
     * @return list<ToolCall>
     */
    private function toolCalls(Message $message): array
    {
        $calls = [];
        foreach ($message->toolCalls as $raw) {
            $call = ToolCall::tryFromArray($raw);
            if ($call instanceof ToolCall) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * Attachments are named in the text, not smuggled in as structure.
     *
     * The model cannot open a FAL uid, and the extracted text of a document is
     * appended to the message when it is uploaded. What is left to say is that
     * a file was attached and what it is called, so a request like "summarise
     * the attached PDF" has something to refer to.
     */
    private function userContent(Message $message): string
    {
        if ($message->attachments === []) {
            return $message->content;
        }

        $names = [];
        foreach ($message->attachments as $attachment) {
            $name = $attachment['fileName'] ?? null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        if ($names === []) {
            return $message->content;
        }

        return trim($message->content . "\n\n" . sprintf('[Attached: %s]', implode(', ', $names)));
    }
}

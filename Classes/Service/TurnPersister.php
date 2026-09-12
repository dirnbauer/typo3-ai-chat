<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Service;

use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Webconsulting\Typo3AiChat\Domain\Model\Conversation;
use Webconsulting\Typo3AiChat\Domain\Model\Message;
use Webconsulting\Typo3AiChat\Domain\Repository\ConversationRepository;
use Webconsulting\Typo3AiChat\Domain\Repository\MessageRepository;
use Webconsulting\Typo3AiChat\Enum\MessageRole;

/**
 * Writes what a run produced back into the transcript.
 *
 * What IS persisted is the conversation: the user's message, the assistant
 * turns, and the tool turns that answer them. Those are not a trace — they are
 * the transcript the NEXT turn replays, and a provider rejects a tool turn
 * whose assistant tool-call turn is missing, so the round-trip has to survive.
 *
 * What is NOT persisted is the trace proper: arguments in full, durations,
 * artifacts, thinking, raw provider bodies. nr-llm already holds all of it
 * against the run uuid, and a second copy would be a second thing to purge, to
 * secure and to keep honest.
 */
final readonly class TurnPersister
{
    /**
     * Tool output is fed back to the model in full but stored bounded: a tool
     * that returns a megabyte of JSON would otherwise make the conversation
     * unloadable forever, to preserve a payload nr-llm already has.
     */
    private const MAX_TOOL_CONTENT = 20000;

    public function __construct(
        private MessageRepository $messageRepository,
        private ConversationRepository $conversationRepository,
        private PendingTurnDigest $pendingTurnDigest,
    ) {}

    /**
     * @param list<array<string, mixed>> $attachments
     */
    public function appendUserMessage(Conversation $conversation, string $content, array $attachments = []): Message
    {
        $message = $this->messageRepository->append(new Message(
            uid: 0,
            conversation: $conversation->getUid(),
            sequence: 0,
            role: MessageRole::User,
            content: $content,
            attachments: $attachments,
            runUuid: $conversation->getRunUuid(),
        ));

        // The first thing a user says is the best title anyone will ever write
        // for the conversation, and it is available now rather than after a
        // round-trip that may fail.
        if ($conversation->getTitle() === '' && trim($content) !== '') {
            $conversation->setTitle($this->titleFrom($content));
        }
        $this->refreshCounters($conversation);

        return $message;
    }

    /**
     * Turn the recorded steps into message rows.
     *
     * The call-id correlation is done here independently of the event stream:
     * a tool step names its tool but not the call it answers, and within one
     * round the loop executes the requested calls in the order they were asked
     * for, so a per-name queue reunites them.
     *
     * @param list<RunStep>                         $steps
     * @param list<array{id: string, name: string}> $resumedCalls calls requested in an
     *                                                            EARLIER segment of the
     *                                                            same run
     *
     * @return list<Message>
     */
    public function persistSteps(
        Conversation $conversation,
        array $steps,
        string $runUuid,
        array $resumedCalls = [],
    ): array {
        // A run resumed after an approval starts a fresh step list, so the
        // assistant turn that asked for these calls is in the PREVIOUS segment.
        // Without seeding them the approved (or denied) tool turns would have no
        // call to answer and be dropped — leaving a transcript whose assistant
        // tool-call message has no reply, which the next turn's provider
        // rejects outright.
        /** @var list<array{id: string, name: string}> $openCalls */
        $openCalls = $resumedCalls;
        $persisted = [];

        foreach ($steps as $step) {
            if ($step->kind === RunStep::KIND_LLM) {
                $requested = $this->wireToolCalls($step);
                if ($requested !== []) {
                    foreach ($step->requestedToolCalls ?? [] as $call) {
                        $openCalls[] = [
                            'id' => is_string($call['id'] ?? null) ? $call['id'] : '',
                            'name' => is_string($call['name'] ?? null) ? $call['name'] : '',
                        ];
                    }

                    $persisted[] = $this->messageRepository->append(new Message(
                        uid: 0,
                        conversation: $conversation->getUid(),
                        sequence: 0,
                        role: MessageRole::Assistant,
                        content: is_string($step->content) ? $step->content : '',
                        toolCalls: $requested,
                        runUuid: $runUuid,
                        promptTokens: $step->promptTokens ?? 0,
                        completionTokens: $step->completionTokens ?? 0,
                    ));

                    continue;
                }

                $content = is_string($step->content) ? trim($step->content) : '';
                if ($content === '') {
                    continue;
                }

                $persisted[] = $this->messageRepository->append(new Message(
                    uid: 0,
                    conversation: $conversation->getUid(),
                    sequence: 0,
                    role: MessageRole::Assistant,
                    content: $content,
                    runUuid: $runUuid,
                    promptTokens: $step->promptTokens ?? 0,
                    completionTokens: $step->completionTokens ?? 0,
                ));

                continue;
            }

            if ($step->kind !== RunStep::KIND_TOOL) {
                continue;
            }

            $toolName = $step->toolName ?? '';
            $callId = '';
            foreach ($openCalls as $index => $call) {
                if ($call['name'] === $toolName) {
                    $callId = $call['id'];
                    unset($openCalls[$index]);
                    $openCalls = array_values($openCalls);
                    break;
                }
            }

            if ($callId === '') {
                // Without an id the turn cannot be replayed: a tool message with
                // no call to answer is rejected by every provider. Dropping it
                // keeps the transcript valid; the full step is still in nr-llm.
                continue;
            }

            $persisted[] = $this->messageRepository->append(new Message(
                uid: 0,
                conversation: $conversation->getUid(),
                sequence: 0,
                role: MessageRole::Tool,
                content: $this->boundedToolContent($step->toolResult ?? ''),
                toolCallId: $callId,
                runUuid: $runUuid,
            ));
        }

        if ($persisted !== []) {
            $this->refreshCounters($conversation);
        }

        return $persisted;
    }

    /**
     * The calls named by a stored approval card, in the shape the persister and
     * the recorder correlate against.
     *
     * @param array<string, mixed> $pendingApproval
     *
     * @return list<array{id: string, name: string}>
     */
    public static function resumedCalls(array $pendingApproval): array
    {
        $raw = $pendingApproval['calls'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $calls = [];
        foreach ($raw as $call) {
            if (!is_array($call)) {
                continue;
            }
            $name = is_string($call['name'] ?? null) ? $call['name'] : '';
            $id = is_string($call['callId'] ?? null) ? $call['callId'] : '';
            if ($name !== '' && $id !== '') {
                $calls[] = ['id' => $id, 'name' => $name];
            }
        }

        return $calls;
    }

    /**
     * The approval card: which calls are pending, and the digest that binds a
     * decision to THIS turn.
     *
     * The digest is not decoration. nr-llm recomputes it from the freshly
     * claimed state and refuses a mismatch (ADR-132), so a stale browser tab
     * cannot authorise calls nobody looked at. Echoing it to the client is what
     * lets the client prove which turn it saw.
     *
     * @return array<string, mixed>
     */
    public function describeSuspension(string $runUuid, SuspendedRunState $state): array
    {
        $calls = [];
        foreach ($state->toolCalls() as $index => $call) {
            $calls[] = [
                'index' => $index,
                'callId' => $call->id,
                'name' => $call->name,
                'arguments' => $call->arguments,
            ];
        }

        return [
            'runUuid' => $runUuid,
            'turnDigest' => $this->pendingTurnDigest->forState($state),
            'calls' => $calls,
        ];
    }

    /**
     * @return array{promptTokens: int, completionTokens: int, totalTokens: int}
     */
    public function usage(AgentRunResult $result): array
    {
        $usage = $result->loopResult?->usage;
        if ($usage !== null) {
            return [
                'promptTokens' => $usage->promptTokens,
                'completionTokens' => $usage->completionTokens,
                'totalTokens' => $usage->totalTokens,
            ];
        }

        // A suspended or failed run has no loop result, but its steps were
        // still paid for — summing them is the only honest number available.
        $prompt = 0;
        $completion = 0;
        foreach ($result->steps as $step) {
            $prompt += $step->promptTokens ?? 0;
            $completion += $step->completionTokens ?? 0;
        }

        return [
            'promptTokens' => $prompt,
            'completionTokens' => $completion,
            'totalTokens' => $prompt + $completion,
        ];
    }

    /**
     * Bring the conversation's denormalised counters back in line with its rows.
     */
    public function refreshCounters(Conversation $conversation): void
    {
        $count = $this->messageRepository->countByConversation($conversation->getUid());
        $conversation->setMessageCount($count);
        $conversation->setLastMessageAt(time());

        $this->conversationRepository->touchMessages(
            $conversation->getUid(),
            $count,
            $conversation->getLastMessageAt(),
            $conversation->getTitle(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wireToolCalls(RunStep $step): array
    {
        $calls = [];
        foreach ($step->requestedToolCalls ?? [] as $call) {
            $id = is_string($call['id'] ?? null) ? $call['id'] : '';
            $name = is_string($call['name'] ?? null) ? $call['name'] : '';
            if ($id === '' || $name === '') {
                continue;
            }

            $arguments = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
            $calls[] = ToolCall::function($id, $name, $arguments)->toArray();
        }

        return $calls;
    }

    private function boundedToolContent(string $content): string
    {
        return mb_strlen($content) > self::MAX_TOOL_CONTENT
            ? mb_substr($content, 0, self::MAX_TOOL_CONTENT) . "\u{2026} [truncated]"
            : $content;
    }

    private function titleFrom(string $content): string
    {
        $normalised = trim(preg_replace('/\s+/u', ' ', $content) ?? $content);

        return mb_strlen($normalised) > 80 ? mb_substr($normalised, 0, 79) . "\u{2026}" : $normalised;
    }
}

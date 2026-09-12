<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Service;

use Closure;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Service\Agent\AgentRuntimeInterface;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Exception\AgentRuntimeException;
use Throwable;
use Webconsulting\Typo3AiChat\Domain\Model\Conversation;
use Webconsulting\Typo3AiChat\Domain\Repository\ConversationRepository;
use Webconsulting\Typo3AiChat\Enum\ConversationStatus;
use Webconsulting\Typo3AiChat\Exception\ChatException;
use Webconsulting\Typo3AiChat\Utility\ErrorMessageSanitizer;

/**
 * Answers the pause a write tool caused.
 *
 * The decision is resumed SYNCHRONOUSLY, for the same reason a turn is: the
 * approved calls are MCP tools, they read the ambient backend user, and the
 * only process where that user exists is the request in which the person
 * approved.
 *
 * The digest is the load-bearing part. nr-llm recomputes it from the freshly
 * claimed state and refuses a mismatch (ADR-132), so a tab that has been open
 * since an earlier turn cannot authorise calls nobody reviewed. This service
 * refuses a missing digest up front rather than letting the runtime do it,
 * because a client that forgot to send one is a bug worth naming — and nr-llm
 * treats "no digest" and "the wrong digest" identically anyway: both prove the
 * reviewed turn is not known.
 */
final readonly class ApprovalService
{
    public function __construct(
        private AgentRuntimeInterface $agentRuntime,
        private ChatTurnService $turnService,
        private ConversationRepository $conversationRepository,
        private BackendUserContext $backendUser,
        private ToolEffectLookup $effectLookup,
    ) {}

    /**
     * @param (Closure(string, array<string, mixed>): void)|null $emit
     *
     * @throws ChatException when the conversation is not waiting for a decision,
     *                       or the decision does not name the turn it decided
     */
    public function decide(
        Conversation $conversation,
        bool $approved,
        string $turnDigest,
        ?Closure $emit = null,
    ): TurnResult {
        $emit ??= static function (string $name, array $payload): void {};

        if ($conversation->getStatus() !== ConversationStatus::AwaitingApproval) {
            throw new ChatException('This conversation is not waiting for an approval.', 1794000201);
        }

        $runUuid = $conversation->getRunUuid();
        if ($runUuid === '') {
            throw new ChatException('This conversation has no run to approve.', 1794000202);
        }

        if (trim($turnDigest) === '') {
            throw new ChatException(
                'The approval must name the turn it decided. Reload the conversation and decide again.',
                1794000203,
            );
        }

        $beUserUid = $this->backendUser->uid();
        $decision = new ApprovalDecision(
            approved: $approved,
            decidedByBeUser: $beUserUid,
            turnDigest: $turnDigest,
        );

        // Re-claim the conversation: the continuation is a turn like any other
        // and must not run twice if the button is pressed twice.
        $claimed = $this->conversationRepository->claimForTurn(
            $conversation->getUid(),
            $conversation->getBeUser(),
            [ConversationStatus::AwaitingApproval],
            $runUuid,
        );
        if (!$claimed) {
            throw new ChatException('This approval has already been decided.', 1794000204);
        }

        $emit('run.started', ['runUuid' => $runUuid, 'userMessageUid' => 0]);

        $resumedCalls = TurnPersister::resumedCalls($conversation->getPendingApproval());

        $recorder = new TurnStepRecorder($this->effectLookup);
        $recorder->seedOpenCalls($resumedCalls);
        $onStep = static function (RunStep $step) use ($recorder, $emit): void {
            $recorder->record($step);
            foreach ($recorder->drainEvents() as [$name, $payload]) {
                $emit($name, $payload);
            }
        };

        try {
            $result = $this->agentRuntime->approve(
                $this->backendUser->actor(),
                $runUuid,
                $decision,
                $onStep,
            );
        } catch (AgentRuntimeException $exception) {
            // The runtime refused BEFORE executing anything: a stale digest, a
            // run that moved on, a configuration that is gone. The conversation
            // goes back to waiting, because the pending calls are still pending.
            $this->conversationRepository->settle(
                $conversation->getUid(),
                $conversation->getBeUser(),
                ConversationStatus::AwaitingApproval,
                '',
                $conversation->getPendingApproval(),
                $runUuid,
            );

            throw new ChatException(ErrorMessageSanitizer::sanitize($exception->getMessage()), 1794000205, $exception);
        } catch (Throwable $exception) {
            $message = ErrorMessageSanitizer::sanitize($exception->getMessage());
            $this->conversationRepository->settle(
                $conversation->getUid(),
                $conversation->getBeUser(),
                ConversationStatus::Failed,
                $message,
            );
            $emit('run.error', ['message' => $message]);

            throw new ChatException($message, 1794000206, $exception);
        }

        $recorder->recordAll($result->steps);

        return $this->turnService->settle($conversation, $result, $recorder, $emit, $resumedCalls);
    }

    /**
     * Stop a run that is still in flight.
     *
     * Cancellation is cooperative in nr-llm: the loop notices at its next step
     * boundary. A step already running — a provider call, a tool — finishes,
     * which is why this returns whether the cancel won the transition rather
     * than whether anything has stopped yet.
     */
    public function cancel(Conversation $conversation): bool
    {
        $runUuid = $conversation->getRunUuid();
        if ($runUuid === '') {
            return false;
        }

        try {
            $cancelled = $this->agentRuntime->cancel($this->backendUser->actor(), $runUuid);
        } catch (Throwable) {
            $cancelled = false;
        }

        $this->conversationRepository->settle(
            $conversation->getUid(),
            $conversation->getBeUser(),
            ConversationStatus::Idle,
            '',
            [],
            '',
        );

        return $cancelled;
    }
}

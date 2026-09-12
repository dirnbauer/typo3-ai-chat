<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Service;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Webconsulting\Typo3AiChat\Enum\ConversationStatus;
use Webconsulting\Typo3AiChat\Utility\ErrorMessageSanitizer;

/**
 * The ONE place that names {@see AgentRunOutcome} cases.
 *
 * nr-llm says outright that outcomes may be added in a minor release and that
 * consumers must not match exhaustively without a default arm. Spreading that
 * match across the controller, the SSE sink and the persistence layer would
 * mean three places to remember on every nr-llm upgrade — and two of them would
 * be found by a user, not by a test. So the enum is read here and turned into
 * this extension's own vocabulary; everything else consumes {@see TurnOutcome}.
 *
 * The default arm settles the conversation as FAILED with a sanitized reason.
 * That is the safe direction for an outcome this version has never heard of: a
 * run whose meaning is unknown must not leave the conversation idle, which
 * would invite the user to send another message on top of it.
 */
final readonly class RunOutcomeMapper
{
    public function map(AgentRunResult $result): TurnOutcome
    {
        return match ($result->outcome) {
            AgentRunOutcome::COMPLETED => new TurnOutcome(
                status: ConversationStatus::Idle,
                finished: true,
                outcome: 'completed',
            ),

            // The run stopped and is waiting for a human. Both pauses look the
            // same to the conversation — it cannot continue by itself — but
            // they are answered by different routes, so the label differs.
            AgentRunOutcome::AWAITING_APPROVAL => new TurnOutcome(
                status: ConversationStatus::AwaitingApproval,
                finished: false,
                outcome: 'awaiting_approval',
            ),
            AgentRunOutcome::AWAITING_INPUT => new TurnOutcome(
                status: ConversationStatus::AwaitingApproval,
                finished: false,
                outcome: 'awaiting_input',
                message: 'A tool is asking for additional input. Continue this run in the nr-llm Agent Runs module.',
            ),

            // A guardrail refused the run. The reason is the guardrail's own
            // message, which is written for an operator and safe to show; it is
            // sanitized anyway, because it may quote provider output.
            AgentRunOutcome::GUARDRAIL_BLOCKED => new TurnOutcome(
                status: ConversationStatus::Failed,
                finished: true,
                outcome: 'guardrail_blocked',
                message: $this->reason($result, 'A guardrail blocked this request.'),
            ),
            AgentRunOutcome::GUARDRAIL_APPROVAL_REQUIRED => new TurnOutcome(
                status: ConversationStatus::Failed,
                finished: true,
                outcome: 'guardrail_approval_required',
                message: $this->reason($result, 'A guardrail requires this request to be approved before it may run.'),
            ),

            AgentRunOutcome::CANCELLED => new TurnOutcome(
                status: ConversationStatus::Idle,
                finished: true,
                outcome: 'cancelled',
                message: 'The turn was cancelled.',
            ),

            // The run was reaped and belongs to another worker now. This
            // extension never enqueues, so reaching it means somebody else
            // drove the same run — and this request must not settle it.
            AgentRunOutcome::LEASE_LOST => new TurnOutcome(
                status: ConversationStatus::Processing,
                finished: false,
                outcome: 'lease_lost',
                message: 'This run is being executed elsewhere.',
                settles: false,
            ),

            // A queued run went back on the queue. Same reasoning as
            // LEASE_LOST: not ours to settle.
            AgentRunOutcome::REQUEUED => new TurnOutcome(
                status: ConversationStatus::Processing,
                finished: false,
                outcome: 'requeued',
                message: 'This run was queued for another attempt.',
                settles: false,
            ),

            // An approval was required but could not be stored, so no resume
            // may be offered (nr-llm ADR-092). Fail, deliberately, rather than
            // showing an approval card that cannot be answered.
            AgentRunOutcome::SUSPEND_FAILED => new TurnOutcome(
                status: ConversationStatus::Failed,
                finished: true,
                outcome: 'suspend_failed',
                message: $this->reason($result, 'The approval for this run could not be recorded, so it was stopped.'),
            ),

            AgentRunOutcome::FAILED => new TurnOutcome(
                status: ConversationStatus::Failed,
                finished: true,
                outcome: 'failed',
                message: $this->reason($result, 'The turn failed.'),
            ),

            default => new TurnOutcome(
                status: ConversationStatus::Failed,
                finished: true,
                outcome: 'failed',
                message: $this->reason(
                    $result,
                    sprintf('The run ended with an outcome this version does not handle (%s).', $result->outcome->value),
                ),
            ),
        };
    }

    private function reason(AgentRunResult $result, string $fallback): string
    {
        $message = $result->error?->getMessage();
        if (!is_string($message) || trim($message) === '') {
            return $fallback;
        }

        return ErrorMessageSanitizer::sanitize($message);
    }
}

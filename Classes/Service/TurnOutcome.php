<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Service;

use Webconsulting\Typo3AiChat\Enum\ConversationStatus;

/**
 * What one turn produced, in this extension's own vocabulary.
 *
 * The translation from nr-llm's {@see \Netresearch\NrLlm\Domain\Enum\AgentRunOutcome}
 * happens once, in {@see RunOutcomeMapper}; everything downstream reads this.
 */
final readonly class TurnOutcome
{
    /**
     * @param ConversationStatus $status   the state the conversation settles into
     * @param bool               $finished whether the turn is over — false means a human
     *                                     or another worker still owes it something
     * @param string             $outcome  the label the client sees on `run.finished`
     * @param string             $message  a human-readable reason, already sanitized
     * @param bool               $settles  whether THIS request may write the conversation's
     *                                     lifecycle at all. False when the run belongs to
     *                                     another executor, where writing would overwrite
     *                                     the state its true owner is maintaining.
     */
    public function __construct(
        public ConversationStatus $status,
        public bool $finished,
        public string $outcome,
        public string $message = '',
        public bool $settles = true,
    ) {}

    public function isFailure(): bool
    {
        return $this->status === ConversationStatus::Failed;
    }

    public function isAwaitingApproval(): bool
    {
        return $this->status === ConversationStatus::AwaitingApproval;
    }
}

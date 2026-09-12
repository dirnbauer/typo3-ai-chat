<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Enum;

/**
 * The lifecycle of one conversation row.
 *
 * Deliberately four states, not seven. The old model tracked WHERE the work was
 * happening — queued, locked by a worker, inside the tool loop, inside a
 * durable workflow — because the work happened somewhere else. A turn now runs
 * inside the request that asked for it, so the only distinctions left are the
 * ones a user can act on: it is your turn, it is the model's turn, it is
 * waiting for your decision, or it broke.
 */
enum ConversationStatus: string
{
    /** Nothing is running; the user may send a message. */
    case Idle = 'idle';

    /**
     * A turn is in flight. This doubles as the per-conversation lock: a second
     * turn is refused while it is set, so one conversation never has two runs
     * writing into it.
     */
    case Processing = 'processing';

    /**
     * The run suspended because the model asked to call a tool that writes. It
     * stays here until somebody approves or denies; the pending calls and the
     * turn digest live in `pending_approval`.
     */
    case AwaitingApproval = 'awaiting_approval';

    /** The last turn ended badly; `error_message` says how. */
    case Failed = 'failed';

    /**
     * Whether a new turn may start from this state. An approval-suspended
     * conversation is excluded on purpose: the way forward from there is a
     * decision, not another message.
     */
    public function acceptsNewTurn(): bool
    {
        return $this === self::Idle || $this === self::Failed;
    }
}

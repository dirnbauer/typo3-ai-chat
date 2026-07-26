<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Enum;

enum ConversationStatus: string
{
    case Idle = 'idle';
    case Processing = 'processing';
    case Locked = 'locked';
    case ToolLoop = 'tool_loop';
    case AwaitingApproval = 'awaiting_approval';
    case FlueRunning = 'flue_running';
    case Failed = 'failed';
}

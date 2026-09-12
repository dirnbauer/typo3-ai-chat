<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Enum;

/**
 * The author of one persisted message row.
 *
 * `System` is stored as well as sent: a snippet describing the module, page and
 * workspace the user was looking at is part of what the model saw, so leaving
 * it out of the transcript would make a replayed conversation differ from the
 * one that actually ran.
 */
enum MessageRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}

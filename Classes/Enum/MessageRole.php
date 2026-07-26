<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Enum;

enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}

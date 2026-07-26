<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Service;

interface ChatProcessorInterface
{
    /**
     * Dispatch conversation processing.
     * The conversation must already be saved with status 'processing'.
     */
    public function dispatch(int $conversationUid): void;
}

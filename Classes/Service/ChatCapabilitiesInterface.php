<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Service;

use Webconsulting\Typo3AiChat\Domain\Model\Conversation;

interface ChatCapabilitiesInterface
{
    /**
     * @return array{visionSupported: bool, maxFileSize: int, supportedFormats: list<string>}
     */
    public function getProviderCapabilities(): array;

    public function decideApproval(Conversation $conversation, bool $approved, int $decidedBy): void;
}

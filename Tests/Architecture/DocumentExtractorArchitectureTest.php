<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architecture tests for document extraction module.
 *
 * Enforces clean architecture boundaries to ensure document extractors
 * remain independent from HTTP controllers and chat services.
 */
final class DocumentExtractorArchitectureTest
{
    /**
     * Document extractors must not depend on ChatService or Controllers.
     *
     * Extractors should be pure, stateless utilities that can be
     * reused in different contexts without coupling to service layers.
     */
    public function extractorsDoNotDependOnChatService(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Webconsulting\Typo3AiChat\Document'))
            ->shouldNotDependOn()
            ->classes(
                Selector::classname(\Webconsulting\Typo3AiChat\Service\ChatService::class),
                Selector::inNamespace('Webconsulting\Typo3AiChat\Controller'),
            );
    }
}

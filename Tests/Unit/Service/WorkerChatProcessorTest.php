<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webconsulting\Typo3AiChat\Service\WorkerChatProcessor;

class WorkerChatProcessorTest extends TestCase
{
    #[Test]
    public function dispatchIsNoOp(): void
    {
        $processor = new WorkerChatProcessor();
        $processor->dispatch(42);

        // No exception, no side-effect — the worker strategy is intentionally a no-op
        self::assertTrue(true);
    }
}

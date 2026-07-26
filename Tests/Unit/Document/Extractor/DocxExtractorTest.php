<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Tests\Unit\Document\Extractor;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Webconsulting\Typo3AiChat\Document\Extractor\DocxExtractor;

class DocxExtractorTest extends TestCase
{
    private DocxExtractor $subject;
    private string $fixtures;

    protected function setUp(): void
    {
        $this->subject = new DocxExtractor();
        $this->fixtures = __DIR__ . '/../../../Fixtures/Documents';
    }

    #[Test]
    public function supportsDocxMimeType(): void
    {
        self::assertContains(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $this->subject->getSupportedMimeTypes(),
        );
    }

    #[Test]
    public function returnsDocxExtension(): void
    {
        self::assertContains('docx', $this->subject->getSupportedFileExtensions());
    }

    #[Test]
    public function isAvailableWhenLibraryInstalled(): void
    {
        // phpoffice/phpword is a hard dep — always true
        self::assertTrue($this->subject->isAvailable());
    }

    #[Test]
    public function validateThrowsForCorruptFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1743000040);
        $this->expectExceptionMessageMatches('/^DOCX validation failed:/');
        $this->subject->validate($this->fixtures . '/corrupt.docx');
    }

    #[Test]
    public function extractReturnsTextFromDocx(): void
    {
        $text = $this->subject->extract($this->fixtures . '/sample.docx');
        self::assertStringContainsString('Hello DOCX', $text);
    }
}

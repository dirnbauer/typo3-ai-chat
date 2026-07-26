<?php

declare(strict_types=1);

namespace Webconsulting\Typo3AiChat\Document\Extractor;

use Smalot\PdfParser\Parser;
use Throwable;
use Webconsulting\Typo3AiChat\Document\DocumentExtractorInterface;
use Webconsulting\Typo3AiChat\Exception\DocumentExtractionException;

final class PdfExtractor implements DocumentExtractorInterface
{
    public function getSupportedMimeTypes(): array
    {
        return ['application/pdf'];
    }

    public function getSupportedFileExtensions(): array
    {
        return ['pdf'];
    }

    public function isAvailable(): bool
    {
        return class_exists(Parser::class);
    }

    public function validate(string $path): void
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($path);
            $details = $pdf->getDetails();
            if (isset($details['Encrypt'])) {
                throw new DocumentExtractionException('PDF is encrypted and cannot be processed', 1743000031);
            }
        } catch (DocumentExtractionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DocumentExtractionException('PDF validation failed: ' . $e->getMessage(), 1743000030, $e);
        }
    }

    public function extract(string $path): string
    {
        try {
            $parser = new Parser();
            return $parser->parseFile($path)->getText();
        } catch (Throwable $e) {
            throw new DocumentExtractionException('PDF extraction failed: ' . $e->getMessage(), 1743000032, $e);
        }
    }
}

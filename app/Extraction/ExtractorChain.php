<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Extraction\Strategies\OcrExtractor;
use App\Extraction\Strategies\OfficeXmlExtractor;
use App\Extraction\Strategies\PdfExtractor;
use App\Extraction\Strategies\PlainTextExtractor;
use App\Extraction\Strategies\UnsupportedExtractor;

/**
 * Picks the strategy for a MIME type.
 *
 * An unknown type resolves to {@see UnsupportedExtractor} rather than
 * throwing: a format doccum cannot read yet is not an error. See spec §7.
 *
 * PdfExtractor is listed before OcrExtractor deliberately: a PDF's own
 * text-layer-vs-OCR decision lives inside PdfExtractor, which composes
 * OcrExtractor itself rather than the chain choosing between them, so
 * OcrExtractor here only ever claims raw image types.
 */
final class ExtractorChain
{
    /** @var list<TextExtractor> */
    private readonly array $strategies;

    public function __construct(
        PlainTextExtractor $plainText,
        OfficeXmlExtractor $officeXml,
        PdfExtractor $pdf,
        OcrExtractor $ocr,
        private readonly UnsupportedExtractor $fallback,
    ) {
        $this->strategies = [$plainText, $officeXml, $pdf, $ocr];
    }

    public function for(string $mime): TextExtractor
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($mime)) {
                return $strategy;
            }
        }

        return $this->fallback;
    }
}

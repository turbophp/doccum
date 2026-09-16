<?php

declare(strict_types=1);

namespace App\Extraction\Strategies;

use App\Enums\ExtractionStatus;
use App\Extraction\ExtractionResult;
use App\Extraction\TextExtractor;
use App\Support\ProcessRunner;

/**
 * Reads a PDF's existing text layer, and falls back to OCR when there is
 * not enough of one to trust.
 *
 * The threshold is the whole point: a PDF with a text layer must never be
 * OCR'd -- OCR is slow and produces worse text than the layer already
 * present -- and a scan must never be indexed as the empty string. See
 * spec §7.
 */
final class PdfExtractor implements TextExtractor
{
    public function __construct(
        private readonly ProcessRunner $processRunner,
        private readonly OcrExtractor $ocr,
    ) {}

    public function supports(string $mime): bool
    {
        return $mime === 'application/pdf';
    }

    public function extract(string $path, string $mime): ExtractionResult
    {
        $pdftotext = $this->processRunner->run(['pdftotext', '-layout', $path, '-']);
        $meaningfulChars = $pdftotext->ok ? $this->meaningfulCharacterCount($pdftotext->output) : 0;
        $threshold = (int) config('doccum.extraction.scanned_pdf_threshold');

        if ($meaningfulChars >= $threshold) {
            return ExtractionResult::done($pdftotext->output, $this->name());
        }

        // Below the threshold (or pdftotext failed outright): this looks
        // like a scan, so hand it to OCR rather than indexing whatever thin
        // or empty text came back.
        $ocrResult = $this->ocr->extract($path, $mime);

        if ($ocrResult->status === ExtractionStatus::Done) {
            return $ocrResult;
        }

        if (! $pdftotext->ok) {
            $error = $pdftotext->error !== '' ? $pdftotext->error : 'pdftotext failed';

            return ExtractionResult::failed($error, $this->name());
        }

        // pdftotext ran but found too little text, and OCR could not do
        // better either (e.g. tesseract is not installed) -- OCR's own
        // outcome (Unsupported, or its own Failed) is the honest answer.
        return $ocrResult;
    }

    public function name(): string
    {
        return 'pdftotext';
    }

    private function meaningfulCharacterCount(string $text): int
    {
        return mb_strlen((string) preg_replace('/\s+/u', '', $text));
    }
}

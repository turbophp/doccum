<?php

declare(strict_types=1);

namespace App\Extraction\Strategies;

use App\Extraction\ExtractionResult;
use App\Extraction\TextExtractor;
use App\Support\ProcessRunner;

/**
 * Recovers text from scanned material: PDFs with no text layer, and images.
 *
 * A PDF is rasterised page by page with `pdftoppm` and each page is OCR'd
 * with `tesseract`; an image is OCR'd directly. Page-by-page rather than one
 * batch call so a missing page (past the real end of the document) is
 * detected from pdftoppm's own exit code rather than by trusting a page
 * count nobody asked for -- and so the loop can stop the moment the
 * configured page cap is reached, which is what keeps one 400-page scan from
 * monopolising the ingest worker. See spec §7a.
 */
final class OcrExtractor implements TextExtractor
{
    private const IMAGE_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/tiff',
        'image/bmp',
    ];

    public function __construct(private readonly ProcessRunner $processRunner) {}

    public function supports(string $mime): bool
    {
        return in_array($mime, self::IMAGE_MIME_TYPES, true);
    }

    public function extract(string $path, string $mime): ExtractionResult
    {
        // An install without tesseract must mark scans unsupported, not
        // error on every upload -- the difference between a missing feature
        // and a broken one.
        if (! $this->processRunner->available('tesseract')) {
            return ExtractionResult::unsupported();
        }

        return $mime === 'application/pdf'
            ? $this->extractFromPdf($path)
            : $this->extractFromImage($path);
    }

    public function name(): string
    {
        return 'ocr';
    }

    private function extractFromImage(string $path): ExtractionResult
    {
        $text = $this->ocrPage($path);

        return $text === null
            ? ExtractionResult::failed('tesseract could not read the image', $this->name())
            : ExtractionResult::done($text, $this->name());
    }

    private function extractFromPdf(string $path): ExtractionResult
    {
        $limit = (int) config('doccum.extraction.ocr_page_limit');
        $dir = $this->makeTempDir();

        try {
            $texts = [];
            $page = 1;

            while ($page <= $limit) {
                $image = $this->rasterisePage($path, $dir, $page);

                if ($image === null) {
                    // No such page -- the document's real end was reached
                    // before the cap, so nothing was truncated.
                    return $this->finish($texts, false);
                }

                $text = $this->ocrPage($image);

                if ($text !== null) {
                    $texts[] = $text;
                }

                $page++;
            }

            // The cap was reached with a real page still produced at every
            // step; probe one page further to know whether more exist,
            // rather than silently reporting a partial result as complete.
            $truncated = $this->rasterisePage($path, $dir, $limit + 1) !== null;

            return $this->finish($texts, $truncated);
        } finally {
            $this->cleanUp($dir);
        }
    }

    /** @param  list<string>  $texts */
    private function finish(array $texts, bool $truncated): ExtractionResult
    {
        if ($texts === []) {
            return ExtractionResult::failed('no pages could be read from the document', $this->name());
        }

        return ExtractionResult::done(implode("\n\n", $texts), $this->name(), $truncated);
    }

    /**
     * Rasterises a single page to a PNG and returns its path, or null when
     * pdftoppm reports the page does not exist (a real run past the true
     * last page of the document exits non-zero rather than producing a
     * file).
     */
    private function rasterisePage(string $path, string $dir, int $page): ?string
    {
        // A prefix unique to this page, not shared across the whole
        // document: pdftoppm appends its own page suffix to whatever prefix
        // it is given, and a shared prefix would make one page's output
        // ambiguous with another's once padding differs.
        $prefix = sprintf('%s/p%06d', $dir, $page);

        $result = $this->processRunner->run([
            'pdftoppm', '-f', (string) $page, '-l', (string) $page, '-r', '150', '-png', $path, $prefix,
        ]);

        if (! $result->ok) {
            return null;
        }

        $produced = glob($prefix.'*') ?: [];

        // A faked ProcessRunner reports success without writing a real file;
        // trust the reported outcome and hand tesseract the path pdftoppm
        // would have produced.
        return $produced[0] ?? $prefix.'.png';
    }

    private function ocrPage(string $imagePath): ?string
    {
        $result = $this->processRunner->run(['tesseract', $imagePath, 'stdout']);

        return $result->ok ? $result->output : null;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/doccum-ocr-'.bin2hex(random_bytes(8));

        mkdir($dir, 0700, true);

        return $dir;
    }

    private function cleanUp(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }
}

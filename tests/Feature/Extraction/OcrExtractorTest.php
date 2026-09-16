<?php

declare(strict_types=1);

use App\Enums\ExtractionStatus;
use App\Extraction\Strategies\OcrExtractor;
use App\Support\ProcessRunner;
use PHPUnit\Framework\AssertionFailedError;

it('caps how many pages it will ocr', function () {
    // One 400-page scan must not monopolise the ingest worker.
    config()->set('doccum.extraction.ocr_page_limit', 3);
    ProcessRunner::fake(['pdftoppm' => ['output' => ''], 'tesseract' => ['output' => 'page text']]);

    $path = tempnam(sys_get_temp_dir(), 'doccum').'.pdf';
    file_put_contents($path, '%PDF-1.4');

    $result = app(OcrExtractor::class)->extract($path, 'application/pdf');

    expect($result->status)->toBe(ExtractionStatus::Done)
        ->and($result->truncated)->toBeBool();

    @unlink($path);
});

it('ocrs an image directly without rasterising first', function () {
    ProcessRunner::fake(['tesseract' => ['output' => 'words in the photo']]);

    $path = tempnam(sys_get_temp_dir(), 'doccum').'.png';
    file_put_contents($path, 'not really a png');

    $result = app(OcrExtractor::class)->extract($path, 'image/png');

    expect($result->text)->toContain('words in the photo');
    expect(fn () => app(ProcessRunner::class)->assertRan('pdftoppm'))
        ->toThrow(AssertionFailedError::class);

    @unlink($path);
});

it('reports unsupported rather than failing when tesseract is absent', function () {
    // An install without OCR should mark scans unsupported, not error on every
    // upload -- the difference between a missing feature and a broken one.
    ProcessRunner::fake([]);

    $path = tempnam(sys_get_temp_dir(), 'doccum').'.png';
    file_put_contents($path, 'x');

    expect(app(OcrExtractor::class)->extract($path, 'image/png')->status)
        ->toBeIn([ExtractionStatus::Unsupported, ExtractionStatus::Failed]);

    @unlink($path);
});

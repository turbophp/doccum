<?php

declare(strict_types=1);

use App\Enums\ExtractionStatus;
use App\Extraction\Strategies\PdfExtractor;
use App\Support\ProcessRunner;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'doccum').'.pdf';
    file_put_contents($this->path, '%PDF-1.4 fake');
});

afterEach(fn () => @unlink($this->path));

it('uses the text layer when there is one', function () {
    ProcessRunner::fake(['pdftotext' => ['output' => str_repeat('real extracted text ', 20)]]);

    $result = app(PdfExtractor::class)->extract($this->path, 'application/pdf');

    expect($result->status)->toBe(ExtractionStatus::Done)
        ->and($result->extractor)->toBe('pdftotext')
        ->and($result->text)->toContain('real extracted text');

    app(ProcessRunner::class)->assertRan('pdftotext');
});

it('falls back to ocr when the text layer is too thin', function () {
    ProcessRunner::fake([
        'pdftotext' => ['output' => 'x'],           // a scan: a stray character
        'pdftoppm' => ['output' => ''],
        'tesseract' => ['output' => 'text recovered by ocr'],
    ]);

    $result = app(PdfExtractor::class)->extract($this->path, 'application/pdf');

    expect($result->extractor)->toBe('ocr')
        ->and($result->text)->toContain('text recovered by ocr');

    app(ProcessRunner::class)->assertRan('tesseract');
});

it('respects the configured threshold', function () {
    config()->set('doccum.extraction.scanned_pdf_threshold', 5);
    ProcessRunner::fake([
        'pdftotext' => ['output' => 'abcdefghij'],  // 10 chars, above 5
        'tesseract' => ['output' => 'should not be used'],
    ]);

    expect(app(PdfExtractor::class)->extract($this->path, 'application/pdf')->extractor)->toBe('pdftotext');
});

it('does not ocr a text pdf even when ocr is available', function () {
    // The expensive path must never run for a document that did not need it.
    ProcessRunner::fake([
        'pdftotext' => ['output' => str_repeat('plenty of text ', 30)],
        'tesseract' => ['output' => 'wasted work'],
    ]);

    app(PdfExtractor::class)->extract($this->path, 'application/pdf');

    expect(fn () => app(ProcessRunner::class)->assertRan('tesseract'))
        ->toThrow(AssertionFailedError::class);
});

it('reports failure when pdftotext itself fails and ocr cannot help', function () {
    ProcessRunner::fake([
        'pdftotext' => ['exitCode' => 1, 'error' => 'damaged file'],
        'pdftoppm' => ['exitCode' => 1, 'error' => 'damaged file'],
    ]);

    $result = app(PdfExtractor::class)->extract($this->path, 'application/pdf');

    expect($result->status)->toBe(ExtractionStatus::Failed)
        ->and($result->error)->toContain('damaged');
});

it('extracts from a genuine pdf', function () {
    // The faked tests above own the decision logic (text layer vs. OCR); this
    // one proves the real pdftotext command line is right.
    if (! app(ProcessRunner::class)->available('pdftotext')) {
        $this->markTestSkipped('pdftotext is not installed on this host');
    }

    // This is a real command-line check, not a threshold check -- the
    // faked tests above already cover the scanned-pdf decision -- so the
    // threshold is dropped to fit "hello world".
    config()->set('doccum.extraction.scanned_pdf_threshold', 1);

    $path = tempnam(sys_get_temp_dir(), 'doccum').'.pdf';
    file_put_contents($path, implode("\n", [
        '%PDF-1.4',
        '1 0 obj',
        '<< /Type /Catalog /Pages 2 0 R >>',
        'endobj',
        '2 0 obj',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        'endobj',
        '3 0 obj',
        '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 200 100] /Contents 5 0 R >>',
        'endobj',
        '4 0 obj',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        'endobj',
        '5 0 obj',
        '<< /Length 44 >>',
        'stream',
        'BT /F1 12 Tf 10 50 Td (hello world) Tj ET',
        'endstream',
        'endobj',
        'xref',
        '0 6',
        '0000000000 65535 f ',
        'trailer',
        '<< /Size 6 /Root 1 0 R >>',
        'startxref',
        '0',
        '%%EOF',
    ]));

    $result = app(PdfExtractor::class)->extract($path, 'application/pdf');

    expect($result->status)->toBe(ExtractionStatus::Done)
        ->and($result->text)->toContain('hello world');

    @unlink($path);
})->group('integration');

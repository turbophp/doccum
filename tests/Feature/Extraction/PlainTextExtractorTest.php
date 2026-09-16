<?php

declare(strict_types=1);

use App\Enums\ExtractionStatus;
use App\Extraction\Strategies\PlainTextExtractor;

it('reads a text file', function () {
    $path = tempnam(sys_get_temp_dir(), 'doccum');
    file_put_contents($path, "line one\nline two");

    $result = app(PlainTextExtractor::class)->extract($path, 'text/plain');

    expect($result->status)->toBe(ExtractionStatus::Done)
        ->and($result->text)->toContain('line one', 'line two')
        ->and($result->extractor)->toBe('plain');

    @unlink($path);
});

it('handles csv and markdown', function () {
    expect(app(PlainTextExtractor::class)->supports('text/plain'))->toBeTrue()
        ->and(app(PlainTextExtractor::class)->supports('text/csv'))->toBeTrue()
        ->and(app(PlainTextExtractor::class)->supports('text/markdown'))->toBeTrue()
        ->and(app(PlainTextExtractor::class)->supports('application/pdf'))->toBeFalse();
});

it('does not choke on invalid utf-8', function () {
    // Scanned exports and old exports routinely contain bad bytes; storing
    // them would break JSON encoding later, in the search index.
    $path = tempnam(sys_get_temp_dir(), 'doccum');
    file_put_contents($path, "valid \xB1\x31 text");

    $result = app(PlainTextExtractor::class)->extract($path, 'text/plain');

    expect(mb_check_encoding($result->text, 'UTF-8'))->toBeTrue()
        ->and($result->text)->toContain('valid');

    @unlink($path);
});

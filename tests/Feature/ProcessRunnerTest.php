<?php

declare(strict_types=1);

use App\Support\ProcessRunner;
use PHPUnit\Framework\AssertionFailedError;

it('runs a real command', function () {
    $result = app(ProcessRunner::class)->run(['echo', 'hello']);

    expect($result->ok)->toBeTrue()
        ->and(trim($result->output))->toBe('hello')
        ->and($result->exitCode)->toBe(0);
});

it('reports a failing command without throwing', function () {
    $result = app(ProcessRunner::class)->run(['sh', '-c', 'echo boom >&2; exit 3']);

    expect($result->ok)->toBeFalse()
        ->and($result->exitCode)->toBe(3)
        ->and(trim($result->error))->toBe('boom');
});

it('reports a missing binary as failure rather than an exception', function () {
    // Extraction must degrade to "unsupported", never crash the worker,
    // when an optional tool is not installed.
    $result = app(ProcessRunner::class)->run(['definitely-not-a-real-binary-xyz']);

    expect($result->ok)->toBeFalse()
        ->and($result->error)->not->toBeEmpty();
});

it('knows whether a binary is available', function () {
    expect(app(ProcessRunner::class)->available('sh'))->toBeTrue()
        ->and(app(ProcessRunner::class)->available('definitely-not-a-real-binary-xyz'))->toBeFalse();
});

it('can be faked, keyed by the binary', function () {
    ProcessRunner::fake([
        'pdftotext' => ['output' => 'faked text'],
        'tesseract' => ['output' => 'ocr text', 'exitCode' => 0],
    ]);

    expect(app(ProcessRunner::class)->run(['pdftotext', '-', '-'])->output)->toBe('faked text')
        ->and(app(ProcessRunner::class)->run(['tesseract', 'a', 'b'])->output)->toBe('ocr text');
});

it('fails a faked binary that was not configured', function () {
    ProcessRunner::fake(['pdftotext' => ['output' => 'x']]);

    expect(app(ProcessRunner::class)->run(['tesseract'])->ok)->toBeFalse();
});

it('records what it ran so a test can assert on it', function () {
    ProcessRunner::fake(['pdftotext' => ['output' => 'x']]);
    app(ProcessRunner::class)->run(['pdftotext', '-layout', '/tmp/a.pdf', '-']);

    app(ProcessRunner::class)->assertRan('pdftotext');
    expect(fn () => app(ProcessRunner::class)->assertRan('tesseract'))
        ->toThrow(AssertionFailedError::class);
});

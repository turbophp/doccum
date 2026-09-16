<?php

declare(strict_types=1);

use App\Extraction\ExtractorChain;
use App\Extraction\Strategies\OfficeXmlExtractor;
use App\Extraction\Strategies\PlainTextExtractor;
use App\Extraction\Strategies\UnsupportedExtractor;

it('picks the right strategy for a mime type', function () {
    $chain = app(ExtractorChain::class);

    expect($chain->for('text/plain'))->toBeInstanceOf(PlainTextExtractor::class)
        ->and($chain->for('application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
        ->toBeInstanceOf(OfficeXmlExtractor::class);
});

it('falls back to unsupported rather than failing', function () {
    // An unknown type is a document doccum cannot read yet, not an error.
    expect(app(ExtractorChain::class)->for('application/x-nintendo-rom'))
        ->toBeInstanceOf(UnsupportedExtractor::class);
});

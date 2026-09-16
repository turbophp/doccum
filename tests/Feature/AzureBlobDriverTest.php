<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

it('registers an azure driver', function () {
    config()->set('filesystems.disks.documents', [
        'driver' => 'azure',
        'connection_string' => 'DefaultEndpointsProtocol=https;AccountName=test;AccountKey=dGVzdA==;EndpointSuffix=core.windows.net',
        'container' => 'doccum',
    ]);

    expect(fn () => Storage::disk('documents'))->not->toThrow(InvalidArgumentException::class);
});

it('does not claim to provide temporary urls it cannot sign', function () {
    // If the adapter cannot sign, DocumentStorage must fall back to a signed
    // application route rather than handing out a broken link.
    config()->set('filesystems.disks.documents', [
        'driver' => 'azure',
        'connection_string' => 'DefaultEndpointsProtocol=https;AccountName=test;AccountKey=dGVzdA==;EndpointSuffix=core.windows.net',
        'container' => 'doccum',
    ]);

    expect(Storage::disk('documents')->providesTemporaryUrls())->toBeBool();
});

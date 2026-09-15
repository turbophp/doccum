<?php

declare(strict_types=1);

use App\Enums\StorageProvider;

it('builds a Cloudflare R2 endpoint from the account id', function () {
    expect(StorageProvider::R2->endpointFor('abc123', null))
        ->toBe('https://abc123.r2.cloudflarestorage.com');
});

it('requires auto as the region for R2', function () {
    // R2 rejects anything else.
    expect(StorageProvider::R2->defaultRegion())->toBe('auto');
});

it('builds a DigitalOcean Spaces endpoint from the region', function () {
    expect(StorageProvider::Spaces->endpointFor(null, 'nyc3'))
        ->toBe('https://nyc3.digitaloceanspaces.com');
});

it('builds a Wasabi endpoint from the region', function () {
    expect(StorageProvider::Wasabi->endpointFor(null, 'eu-central-1'))
        ->toBe('https://s3.eu-central-1.wasabisys.com');
});

it('builds a Backblaze B2 endpoint from the region', function () {
    expect(StorageProvider::BackblazeB2->endpointFor(null, 'us-west-004'))
        ->toBe('https://s3.us-west-004.backblazeb2.com');
});

it('leaves the endpoint to the operator for plain S3 and custom', function () {
    expect(StorageProvider::S3->endpointFor(null, 'eu-west-1'))->toBeNull()
        ->and(StorageProvider::Custom->endpointFor(null, null))->toBeNull();
});

it('uses path style addressing only where it is required', function () {
    // MinIO needs it; the hosted providers use virtual-hosted buckets.
    expect(StorageProvider::Embedded->usesPathStyle())->toBeTrue()
        ->and(StorageProvider::R2->usesPathStyle())->toBeFalse()
        ->and(StorageProvider::Spaces->usesPathStyle())->toBeFalse()
        ->and(StorageProvider::S3->usesPathStyle())->toBeFalse();
});

it('knows which providers are s3 compatible', function () {
    expect(StorageProvider::R2->isS3Compatible())->toBeTrue()
        ->and(StorageProvider::Embedded->isS3Compatible())->toBeTrue()
        ->and(StorageProvider::AzureBlob->isS3Compatible())->toBeFalse();
});

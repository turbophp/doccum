<?php

declare(strict_types=1);

use App\Providers\RuntimeConfigServiceProvider;
use App\Services\Settings;

/**
 * RuntimeConfigServiceProvider::boot() derives endpoint, region, and
 * addressing style from a chosen storage.provider preset -- see
 * App\Enums\StorageProvider and plan Task 3.
 */
it('derives an R2 endpoint, its fixed region, and virtual-hosted addressing', function () {
    app(Settings::class)->set('storage.provider', 'r2');
    app(Settings::class)->set('storage.account', 'abc123');

    (new RuntimeConfigServiceProvider(app()))->boot();

    expect(config('filesystems.disks.documents.endpoint'))->toBe('https://abc123.r2.cloudflarestorage.com')
        ->and(config('filesystems.disks.documents.region'))->toBe('auto')
        ->and(config('filesystems.disks.documents.use_path_style_endpoint'))->toBeFalse();
});

it('derives a region-based preset endpoint for Wasabi', function () {
    app(Settings::class)->set('storage.provider', 'wasabi');
    app(Settings::class)->set('storage.region', 'eu-central-1');

    (new RuntimeConfigServiceProvider(app()))->boot();

    expect(config('filesystems.disks.documents.endpoint'))->toBe('https://s3.eu-central-1.wasabisys.com');
});

it('sets path style addressing back on when the provider is embedded', function () {
    config()->set('filesystems.disks.documents.use_path_style_endpoint', false);
    app(Settings::class)->set('storage.provider', 'embedded');

    (new RuntimeConfigServiceProvider(app()))->boot();

    expect(config('filesystems.disks.documents.use_path_style_endpoint'))->toBeTrue();
});

it('lets an explicit endpoint setting win over the preset default', function () {
    // A preset is a default, not a cage.
    app(Settings::class)->set('storage.provider', 'r2');
    app(Settings::class)->set('storage.account', 'abc123');
    app(Settings::class)->set('storage.endpoint', 'https://overridden.example.com');

    (new RuntimeConfigServiceProvider(app()))->boot();

    expect(config('filesystems.disks.documents.endpoint'))->toBe('https://overridden.example.com');
});

it('leaves the documents disk untouched when no provider setting is stored', function () {
    $before = config('filesystems.disks.documents');

    (new RuntimeConfigServiceProvider(app()))->boot();

    expect(config('filesystems.disks.documents'))->toBe($before);
});

it('ignores an unrecognised provider value rather than throwing', function () {
    app(Settings::class)->set('storage.provider', 'not-a-real-provider');

    // Guards against reaching for StorageProvider::from() instead of
    // tryFrom() -- from() throws ValueError on an unmapped backing value.
    expect(fn () => (new RuntimeConfigServiceProvider(app()))->boot())->not->toThrow(ValueError::class);
});

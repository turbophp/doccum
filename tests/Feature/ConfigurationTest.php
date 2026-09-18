<?php

declare(strict_types=1);

use App\Providers\DoccumServiceProvider;
use Illuminate\Support\Facades\Storage;

it('ships product defaults in config', function () {
    expect(config('doccum.version'))->toBeString()
        ->and(config('doccum.settings.auth.public_signup'))->toBeFalse()
        ->and(config('doccum.settings.directories.auto_home'))->toBeTrue()
        ->and(config('doccum.settings.instance.name'))->toBe('doccum')
        ->and(config('doccum.extraction.scanned_pdf_threshold'))->toBe(100)
        ->and(config('doccum.settings.retention.auto_purge'))->toBeFalse();
});

it('defines a documents disk backed by s3', function () {
    expect(config('filesystems.disks.documents.driver'))->toBe('s3')
        ->and(config('filesystems.disks.documents.use_path_style_endpoint'))->toBeTrue();

    Storage::fake('documents');
    Storage::disk('documents')->put('probe.txt', 'ok');
    expect(Storage::disk('documents')->get('probe.txt'))->toBe('ok');
});

it('registers the single registration point', function () {
    expect(app()->getProviders(DoccumServiceProvider::class))->not->toBeEmpty();
});

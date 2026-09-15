<?php

declare(strict_types=1);

use App\Providers\RuntimeConfigServiceProvider;
use App\Support\RuntimeConfig;

beforeEach(function () {
    $this->file = sys_get_temp_dir().'/doccum-runtime-'.uniqid().'.json';
    config()->set('doccum.runtime_config_path', $this->file);
});

afterEach(function () {
    config()->set('database.default', 'sqlite');
    @unlink($this->file);
});

it('leaves config untouched when no file exists', function () {
    $before = config('database.default');

    (new RuntimeConfigServiceProvider(app()))->applyOverrides();

    expect(config('database.default'))->toBe($before)
        ->and(RuntimeConfigServiceProvider::hasError())->toBeFalse();
});

it('overrides the database connection', function () {
    RuntimeConfig::write([
        'database' => [
            'connection' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'doccum',
            'username' => 'doccum',
            'password' => 'hunter2',
        ],
    ]);

    (new RuntimeConfigServiceProvider(app()))->applyOverrides();

    expect(config('database.default'))->toBe('pgsql')
        ->and(config('database.connections.pgsql.host'))->toBe('db.internal')
        ->and(config('database.connections.pgsql.database'))->toBe('doccum');
});

it('overrides the documents disk from settings', function () {
    app(App\Services\Settings::class)->set('storage.endpoint', 'https://s3.example.com');
    app(App\Services\Settings::class)->set('storage.bucket', 'papers');
    app(App\Services\Settings::class)->set('storage.region', 'eu-west-1');

    (new RuntimeConfigServiceProvider(app()))->boot();

    expect(config('filesystems.disks.documents.endpoint'))->toBe('https://s3.example.com')
        ->and(config('filesystems.disks.documents.bucket'))->toBe('papers')
        ->and(config('filesystems.disks.documents.region'))->toBe('eu-west-1');
});

it('beats an environment value', function () {
    config()->set('filesystems.disks.documents.bucket', 'from-env');
    app(App\Services\Settings::class)->set('storage.bucket', 'from-installer');

    (new RuntimeConfigServiceProvider(app()))->boot();

    expect(config('filesystems.disks.documents.bucket'))->toBe('from-installer');
});

it('records an error instead of throwing when the file is unreadable', function () {
    file_put_contents($this->file, '{ not json');

    (new RuntimeConfigServiceProvider(app()))->applyOverrides();

    expect(RuntimeConfigServiceProvider::hasError())->toBeTrue()
        ->and(RuntimeConfigServiceProvider::error())->toContain('APP_KEY');
});

it('does not fall back to environment config when unreadable', function () {
    config()->set('filesystems.disks.documents.bucket', 'from-env');
    file_put_contents($this->file, '{ not json');

    (new RuntimeConfigServiceProvider(app()))->applyOverrides();

    // The value is untouched, but the error flag is what stops the app being
    // treated as a fresh install. See the guard in Task 6.
    expect(RuntimeConfigServiceProvider::hasError())->toBeTrue();
});

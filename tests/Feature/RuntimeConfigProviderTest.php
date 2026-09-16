<?php

declare(strict_types=1);

use App\Providers\RuntimeConfigServiceProvider;
use App\Services\Settings;
use App\Support\RuntimeConfig;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->originalDefaultConnection = config('database.default');
    $this->file = sys_get_temp_dir().'/doccum-runtime-'.uniqid().'.json';
    config()->set('doccum.runtime_config_path', $this->file);
});

afterEach(function () {
    // Restored to whatever the suite was actually running on, not pinned to
    // sqlite. These tests repoint database.default on purpose, and
    // RefreshDatabase resolves that key lazily at rollback time (see
    // CLAUDE.md), so hardcoding it sends the rollback at the wrong
    // connection: on a postgres or mysql run the real transaction is never
    // closed, and the next test waits on the row locks it still holds until
    // the server gives up on it.
    config()->set('database.default', $this->originalDefaultConnection);
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
    app(Settings::class)->set('storage.endpoint', 'https://s3.example.com');
    app(Settings::class)->set('storage.bucket', 'papers');
    app(Settings::class)->set('storage.region', 'eu-west-1');

    (new RuntimeConfigServiceProvider(app()))->boot();

    expect(config('filesystems.disks.documents.endpoint'))->toBe('https://s3.example.com')
        ->and(config('filesystems.disks.documents.bucket'))->toBe('papers')
        ->and(config('filesystems.disks.documents.region'))->toBe('eu-west-1');
});

it('beats an environment value', function () {
    config()->set('filesystems.disks.documents.bucket', 'from-env');
    app(Settings::class)->set('storage.bucket', 'from-installer');

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

it('boots without a reachable database', function () {
    // An image build runs `composer dump-autoload`, which fires
    // package:discover with no database present at all. Throwing here fails
    // the build -- and CI always builds cold.
    config()->set('database.connections.broken', [
        'driver' => 'sqlite',
        'database' => '/nonexistent/dir/no.sqlite',
    ]);
    config()->set('database.default', 'broken');

    expect(fn () => (new RuntimeConfigServiceProvider(app()))->boot())
        ->not->toThrow(QueryException::class);
});

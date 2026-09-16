<?php

declare(strict_types=1);

use App\Livewire\Setup\FirstRun;
use App\Models\User;
use App\Services\Settings;
use App\Support\RuntimeConfig;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->originalDefaultConnection = config('database.default');
    $this->file = sys_get_temp_dir().'/doccum-runtime-'.uniqid().'.json';
    config()->set('doccum.runtime_config_path', $this->file);
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    @unlink($this->file);

    // saveDatabase() purges and re-migrates the connection it just validated.
    // When that connection is the same one RefreshDatabase is wrapping in a
    // transaction (sqlite :memory: in this suite), the purge discards
    // Laravel's Connection wrapper but not the underlying PDO's open
    // transaction -- RefreshDatabaseState keeps a raw handle to it for reuse
    // across tests, and that handle is left stuck mid-transaction forever.
    // Clearing it forces the next test to resolve (and migrate) a clean
    // connection instead of restoring the poisoned one. See Global
    // Constraints re: the test-isolation trap.
    RefreshDatabaseState::$inMemoryConnections = [];
    RefreshDatabaseState::$migrated = false;

    config()->set('database.default', $this->originalDefaultConnection);
});

it('starts advanced configuration on the database step', function () {
    // The default flow starts on the administrator step; database and storage
    // are embedded and only appear when the operator opts in.
    Livewire::test(FirstRun::class)
        ->call('enterAdvanced')
        ->assertSet('step', 1);
});

it('refuses to advance past a database it cannot reach', function () {
    Livewire::test(FirstRun::class)
        ->call('enterAdvanced')
        ->set('db_connection', 'pgsql')
        ->set('db_host', '127.0.0.1')
        ->set('db_port', 1)
        ->set('db_database', 'nope')
        ->set('db_username', 'nope')
        ->set('db_password', 'nope')
        ->call('saveDatabase')
        ->assertHasErrors('db_connection')
        ->assertSet('step', 1);

    expect(RuntimeConfig::exists())->toBeFalse();
});

it('advances to storage when the database works', function () {
    Livewire::test(FirstRun::class)
        ->call('enterAdvanced')
        ->set('db_connection', 'sqlite')
        ->set('db_database', config('database.connections.sqlite.database'))
        ->call('saveDatabase')
        ->assertHasNoErrors()
        ->assertSet('step', 2);
});

it('lets the operator keep the default storage', function () {
    Storage::fake('documents');

    Livewire::test(FirstRun::class)
        ->set('advanced', true)
        ->set('step', 2)
        ->call('skipStorage')
        ->assertSet('step', 3);
});

it('refuses storage it cannot write to', function () {
    Livewire::test(FirstRun::class)
        ->set('advanced', true)
        ->set('step', 2)
        ->set('s3_endpoint', 'http://127.0.0.1:1')
        ->set('s3_key', 'k')
        ->set('s3_secret', 's')
        ->set('s3_bucket', 'b')
        ->call('saveStorage')
        ->assertHasErrors('s3_endpoint')
        ->assertSet('step', 2);
});

it('creates the admin on the final step', function () {
    Storage::fake('documents');

    Livewire::test(FirstRun::class)
        ->set('step', 3)
        ->set('instance_name', 'Acme Docs')
        ->set('name', 'Ada Lovelace')
        ->set('username', 'ada')
        ->set('email', 'ada@example.com')
        ->set('password', 'password-please')
        ->set('password_confirmation', 'password-please')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect(User::firstOrFail()->hasRole('admin'))->toBeTrue();
});

it('never writes a storage secret into the runtime file at all', function () {
    Storage::fake('documents');

    // ConnectionProbe::storage() probes through a disk named "doccum_probe"
    // when no explicit disk is given (see app/Services/ConnectionProbe.php) --
    // faking "documents" alone leaves that probe disk pointed at whatever S3
    // endpoint config/filesystems.php resolves to, which would otherwise
    // require a real network call to a host named "minio".
    Storage::fake('doccum_probe');

    Livewire::test(FirstRun::class)
        ->set('advanced', true)
        ->set('step', 2)
        ->set('s3_endpoint', 'http://minio:9000')
        ->set('s3_key', 'doccum')
        ->set('s3_secret', 'a-very-secret-string')
        ->set('s3_bucket', 'doccum')
        ->call('saveStorage');

    // Storage credentials belong in the settings table, not the file.
    if (RuntimeConfig::exists()) {
        expect(file_get_contents($this->file))->not->toContain('a-very-secret-string');
    }

    expect(app(Settings::class)->get('storage.secret'))->toBe('a-very-secret-string');
});

it('swaps the database field between a file path and server credentials', function () {
    // The screenshot bug: picking MariaDB still asked for a "Database file
    // path" because wire:model is deferred, so the select never reached the
    // server and the form never re-rendered.
    Livewire::test(FirstRun::class)
        ->call('enterAdvanced')
        ->assertSet('db_connection', 'sqlite')
        ->assertSet('db_database', '/data/doccum.sqlite')
        ->set('db_connection', 'mariadb')
        ->assertSet('db_database', '')
        ->assertSet('db_port', '3306')
        ->assertSee('Host')
        ->assertSee('Username')
        ->assertSee('Password')
        ->assertDontSee('Database file path');
});

it('restores the embedded path when switching back', function () {
    Livewire::test(FirstRun::class)
        ->call('enterAdvanced')
        ->set('db_connection', 'pgsql')
        ->assertSet('db_port', '5432')
        ->set('db_connection', 'sqlite')
        ->assertSet('db_database', '/data/doccum.sqlite')
        ->assertSee('Database file path')
        ->assertDontSee('Host');
});

it('describes the embedded database in the same language as embedded storage', function () {
    Livewire::test(FirstRun::class)->call('enterAdvanced')->assertSee('Embedded (SQLite)');
});

it('asks only for the administrator account by default', function () {
    // The common install configures nothing: database and storage are embedded.
    Livewire::test(FirstRun::class)
        ->assertSet('advanced', false)
        ->assertSet('step', 3)
        ->assertSee('Username')
        ->assertSee('Create administrator account')
        ->assertDontSee('Database type')
        ->assertDontSee('Storage provider');
});

it('offers advanced configuration for people using their own infrastructure', function () {
    Livewire::test(FirstRun::class)
        ->assertSee('Advanced configuration')
        ->call('enterAdvanced')
        ->assertSet('advanced', true)
        ->assertSet('step', 1)
        ->assertSee('Database type');
});

it('lets the operator fall back to the embedded defaults', function () {
    Livewire::test(FirstRun::class)
        ->call('enterAdvanced')
        ->call('leaveAdvanced')
        ->assertSet('advanced', false)
        ->assertSet('step', 3)
        ->assertDontSee('Database type');
});

it('completes a default install without touching database or storage config', function () {
    Livewire::test(FirstRun::class)
        ->set('instance_name', 'Acme Docs')
        ->set('name', 'Ada Lovelace')
        ->set('username', 'ada')
        ->set('email', 'ada@example.com')
        ->set('password', 'Correct-Horse-Battery9')
        ->set('password_confirmation', 'Correct-Horse-Battery9')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect(User::firstOrFail()->hasRole('admin'))->toBeTrue()
        ->and(RuntimeConfig::exists())->toBeFalse();
});

it('offers a way back to the embedded defaults from every advanced step', function () {
    // The browser found this: the escape hatch only existed on the last step,
    // so an operator who opened advanced config was stuck with it.
    foreach ([1, 2, 3] as $step) {
        Livewire::test(FirstRun::class)
            ->set('advanced', true)
            ->set('step', $step)
            ->assertSee('Use embedded database and storage instead');
    }
});

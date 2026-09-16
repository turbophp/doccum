<?php

declare(strict_types=1);

use App\Livewire\Setup\FirstRun;
use App\Models\User;
use App\Services\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->originalDefaultConnection = config('database.default');
    $this->originalSqliteConfig = config('database.connections.sqlite');
    $this->file = sys_get_temp_dir().'/doccum-runtime-'.uniqid().'.json';
    config()->set('doccum.runtime_config_path', $this->file);
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    @unlink($this->file);

    // saveDatabase() purges and re-migrates whatever connection it just
    // validated. When that's the same connection RefreshDatabase wraps in a
    // transaction (sqlite :memory: in this suite), the purge discards
    // Laravel's Connection wrapper but not the underlying PDO's open
    // transaction -- RefreshDatabaseState caches a raw handle to it for reuse
    // across tests, and that handle is left stuck mid-transaction forever
    // unless cleared here. See Global Constraints re: the test-isolation trap.
    RefreshDatabaseState::$inMemoryConnections = [];
    RefreshDatabaseState::$migrated = false;

    config()->set('database.default', $this->originalDefaultConnection);
    config()->set('database.connections.sqlite', $this->originalSqliteConfig);
});

/**
 * Builds a real, file-backed sqlite database standing in for "a doccum
 * instance from a previous deployment" and returns its path.
 *
 * Deliberately NOT the suite's own ":memory:" bootstrap connection. SQLite's
 * ":memory:" databases are private to the single PDO connection that opened
 * them -- a second connection to ":memory:" is a second, empty database, even
 * with byte-identical config (confirmed empirically while implementing this
 * test: a fresh connection cannot see a table created through another
 * ":memory:" connection in the same process). ConnectionProbe::inspect() is
 * required to run on its own disposable connection rather than the live
 * default one (see saveDatabase()'s docblock), so it can only ever observe
 * data that is actually reachable over a second connection -- which is
 * exactly how a real Postgres/MySQL server, or a real sqlite *file*, behaves,
 * and exactly how ":memory:" does not. Using a real file here is what makes
 * these tests exercise the real cross-connection visibility the safety
 * ordering depends on, instead of a testing artifact.
 */
function attachTargetDatabase(?string $keyCheckValue): string
{
    $path = sys_get_temp_dir().'/doccum-attach-'.uniqid().'.sqlite';
    touch($path);

    config()->set('database.connections.attach_target', [
        'driver' => 'sqlite',
        'database' => $path,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    Artisan::call('migrate', ['--database' => 'attach_target', '--force' => true]);

    DB::connection('attach_target')->table('users')->insert([
        'name' => 'Existing Admin',
        'username' => 'existing-admin',
        'email' => 'existing-admin@example.com',
        'password' => bcrypt('already-set-password'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($keyCheckValue !== null) {
        DB::connection('attach_target')->table('settings')->insert([
            'key' => 'instance.key_check',
            'value' => json_encode($keyCheckValue),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::purge('attach_target');
    config()->set('database.connections.attach_target', null);

    return $path;
}

it('treats a database with no users as a fresh install', function () {
    Livewire::test(FirstRun::class)
        ->set('db_connection', 'sqlite')
        ->set('db_database', config('database.connections.sqlite.database'))
        ->call('saveDatabase')
        ->assertSet('attaching', false)
        ->assertSet('step', 2);
});

it('detects a populated database and skips straight past setup', function () {
    $path = attachTargetDatabase(encrypt('doccum'));

    Livewire::test(FirstRun::class)
        ->set('db_connection', 'sqlite')
        ->set('db_database', $path)
        ->call('saveDatabase')
        ->assertSet('attaching', true)
        ->assertRedirect(route('login'));

    @unlink($path);
});

it('never offers to create an admin when attaching', function () {
    $path = attachTargetDatabase(encrypt('doccum'));

    Livewire::test(FirstRun::class)
        ->set('db_connection', 'sqlite')
        ->set('db_database', $path)
        ->call('saveDatabase');

    // The live connection now points at the attached database (saveDatabase()
    // applies it before redirecting), so this counts users in that database --
    // the one the installer must never add a second admin to.
    expect(User::count())->toBe(1);

    @unlink($path);
});

it('refuses to attach when APP_KEY does not match', function () {
    // A canary this APP_KEY cannot decrypt.
    $bogusKeyCheck = 'eyJpdiI6ImJvZ3VzIiwidmFsdWUiOiJib2d1cyJ9';
    $path = attachTargetDatabase($bogusKeyCheck);

    Livewire::test(FirstRun::class)
        // Pointing at an existing database is advanced configuration; the
        // default flow never leaves the administrator step.
        ->call('enterAdvanced')
        ->set('db_connection', 'sqlite')
        ->set('db_database', $path)
        ->call('saveDatabase')
        ->assertHasErrors('db_connection')
        ->assertSet('attaching', false)
        ->assertSet('step', 1);

    // Nothing was written, migrated or switched: the target database's own
    // canary is exactly as it was, untouched by the failed attach attempt.
    config()->set('database.connections.attach_target_verify', [
        'driver' => 'sqlite',
        'database' => $path,
        'prefix' => '',
    ]);

    expect(
        DB::connection('attach_target_verify')->table('settings')->where('key', 'instance.key_check')->value('value')
    )->toBe(json_encode($bogusKeyCheck));

    DB::purge('attach_target_verify');
    config()->set('database.connections.attach_target_verify', null);

    @unlink($path);
});

it('writes a key canary on a fresh install', function () {
    Livewire::test(FirstRun::class)
        ->set('step', 3)
        ->set('instance_name', 'Acme')
        ->set('name', 'Ada')
        ->set('username', 'ada')
        ->set('email', 'ada@example.com')
        ->set('password', 'password-please')
        ->set('password_confirmation', 'password-please')
        ->call('submit');

    expect(decrypt(app(Settings::class)->get('instance.key_check')))->toBe('doccum');
});

it('refuses to create an admin when the instance already has users', function () {
    // The real safety property: not "the wizard redirects", but "submit cannot
    // mint a second admin". Livewire calls bypass the /setup route guard, so
    // this must hold at the component.
    User::factory()->create(['username' => 'existing']);

    Livewire::test(FirstRun::class)
        ->set('step', 3)
        ->set('instance_name', 'Hostile Takeover')
        ->set('name', 'Mallory')
        ->set('username', 'mallory')
        ->set('email', 'mallory@example.com')
        ->set('password', 'password-please')
        ->set('password_confirmation', 'password-please')
        ->call('submit')
        ->assertNotFound();

    expect(User::count())->toBe(1)
        ->and(User::where('username', 'mallory')->exists())->toBeFalse();
});

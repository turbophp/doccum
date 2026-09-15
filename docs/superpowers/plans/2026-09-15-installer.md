# doccum Installer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an operator configure database and object storage through the browser on first run, so `docker compose up` needs no file editing at all.

**Architecture:** Two stores, split by what each is able to bootstrap. The encrypted file on the data volume holds **only the database connection** — the one genuine chicken-and-egg — and is applied in `register()`, before any connection resolves. Everything else, storage credentials included, lives in the `settings` table and is applied in `boot()`, because nothing resolves a disk during boot and the database is available by then. That keeps the file minimal, gives operator settings a single source of truth, and means a database backup carries the storage configuration with it.

**Tech Stack:** Laravel 13, Livewire 4, Pest 5.

**Spec:** `docs/superpowers/specs/2026-09-15-doccum-design.md` §10a

**Previous plans:** foundation, access-control, storage — all merged.

## Global Constraints

- **Never edit anything under `vendor/`;** never edit a framework migration.
- `declare(strict_types=1);` on every PHP file authored.
- Models use `#[Fillable([...])]`.
- Nothing outside `App\Services\DocumentStorage` resolves a disk (tests may `Storage::fake`).
- **Never pass an interface to `toThrow()`** — Pest branches on `class_exists()`, false for interfaces, and silently degrades to a substring match on the message.
- **The runtime config file must never be written under `storage/`.** That path is inside the image, not the volume; anything there is lost on rebuild. Default `/data/runtime.json`, overridable by `DOCCUM_RUNTIME_CONFIG`.
- **Secrets never reach a log, an exception message, or a test assertion in plaintext.**
- Pest for all tests; each task ends with a green FULL suite and its own commit.
- Baseline entering this plan: **168 tests, 366 assertions**.

---

### Task 1: The runtime config store

**Files:**
- Create: `app/Support/RuntimeConfig.php`, `app/Exceptions/RuntimeConfigUnreadable.php`
- Test: `tests/Feature/RuntimeConfigTest.php`

**Interfaces:**
- `RuntimeConfig::path(): string`
- `RuntimeConfig::exists(): bool`
- `RuntimeConfig::read(): array` — throws `RuntimeConfigUnreadable` on bad ciphertext
- `RuntimeConfig::write(array $payload): void` — atomic, mode 0600
- `RuntimeConfig::forget(): void`

Deliberately a plain class in `app/Support`, not a container service: the
provider that consumes it runs before the container is useful.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Exceptions\RuntimeConfigUnreadable;
use App\Support\RuntimeConfig;

beforeEach(function () {
    $this->file = sys_get_temp_dir().'/doccum-runtime-'.uniqid().'.json';
    config()->set('doccum.runtime_config_path', $this->file);
});

afterEach(fn () => @unlink($this->file));

it('reports absence before anything is written', function () {
    expect(RuntimeConfig::exists())->toBeFalse()
        ->and(RuntimeConfig::read())->toBe([]);
});

it('round trips a payload', function () {
    RuntimeConfig::write(['database' => ['host' => 'db', 'password' => 'hunter2']]);

    expect(RuntimeConfig::exists())->toBeTrue()
        ->and(RuntimeConfig::read())->toBe(['database' => ['host' => 'db', 'password' => 'hunter2']]);
});

it('does not store secrets in plaintext on disk', function () {
    RuntimeConfig::write(['storage' => ['secret' => 'super-secret-value']]);

    $raw = file_get_contents($this->file);

    expect($raw)->not->toContain('super-secret-value')
        ->and($raw)->not->toContain('storage');
});

it('writes a readable envelope around the ciphertext', function () {
    RuntimeConfig::write(['a' => 1]);

    $raw = json_decode(file_get_contents($this->file), true);

    expect($raw)->toHaveKeys(['doccum', 'payload'])
        ->and($raw['doccum'])->toBe(1);
});

it('writes the file private to its owner', function () {
    RuntimeConfig::write(['a' => 1]);

    expect(substr(sprintf('%o', fileperms($this->file)), -3))->toBe('600');
});

it('refuses to guess when the ciphertext cannot be decrypted', function () {
    RuntimeConfig::write(['a' => 1]);
    file_put_contents($this->file, json_encode(['doccum' => 1, 'payload' => 'not-valid-ciphertext']));

    expect(fn () => RuntimeConfig::read())->toThrow(RuntimeConfigUnreadable::class);
});

it('treats a corrupt envelope as unreadable rather than empty', function () {
    file_put_contents($this->file, '{ this is not json');

    expect(fn () => RuntimeConfig::read())->toThrow(RuntimeConfigUnreadable::class);
});

it('forgets the file', function () {
    RuntimeConfig::write(['a' => 1]);
    RuntimeConfig::forget();

    expect(RuntimeConfig::exists())->toBeFalse();
});
```

The "unreadable rather than empty" tests are the important pair. Returning `[]`
for a corrupt file would make a configured instance look brand new, and the
installer would happily overwrite a live deployment.

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Write the exception**

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class RuntimeConfigUnreadable extends RuntimeException
{
    public static function at(string $path): self
    {
        return new self(
            "The runtime configuration at {$path} exists but could not be read. "
            .'This usually means APP_KEY has changed since it was written. '
            .'Restore the original APP_KEY, or remove the file to reconfigure from scratch.'
        );
    }
}
```

The message never includes the file's contents — only its path.

- [ ] **Step 4: Write the store**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\RuntimeConfigUnreadable;
use Illuminate\Encryption\Encrypter;
use Throwable;

/**
 * Encrypted runtime overrides on the data volume.
 *
 * A plain class rather than a container service: the provider that consumes it
 * runs in register(), before the container is useful, and the credentials it
 * carries are the ones the container would otherwise need to boot.
 *
 * Encryption uses APP_KEY, which lives on the same volume. That is defence in
 * depth, not a security boundary -- it keeps credentials out of backups,
 * support bundles and logs, but does not defend against someone who can read
 * the volume. See spec §10a.
 */
final class RuntimeConfig
{
    private const VERSION = 1;

    public static function path(): string
    {
        return (string) config('doccum.runtime_config_path');
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    /** @return array<string, mixed> */
    public static function read(): array
    {
        if (! self::exists()) {
            return [];
        }

        $raw = @file_get_contents(self::path());
        $envelope = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($envelope) || ! isset($envelope['payload'])) {
            throw RuntimeConfigUnreadable::at(self::path());
        }

        try {
            $decoded = self::encrypter()->decrypt($envelope['payload']);
        } catch (Throwable) {
            // Deliberately not falling back to an empty array: a configured
            // instance must never look like a fresh one.
            throw RuntimeConfigUnreadable::at(self::path());
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @param  array<string, mixed>  $payload */
    public static function write(array $payload): void
    {
        $envelope = json_encode([
            'doccum' => self::VERSION,
            'payload' => self::encrypter()->encrypt($payload),
        ], JSON_PRETTY_PRINT);

        $path = self::path();
        @mkdir(dirname($path), 0755, true);

        // Written to a temporary file then renamed: a half-written config is
        // indistinguishable from a corrupt one, and corrupt means locked out.
        $temp = $path.'.'.getmypid().'.tmp';
        file_put_contents($temp, $envelope);
        chmod($temp, 0600);
        rename($temp, $path);
    }

    public static function forget(): void
    {
        if (self::exists()) {
            @unlink(self::path());
        }
    }

    private static function encrypter(): Encrypter
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return new Encrypter($key, (string) config('app.cipher', 'AES-256-CBC'));
    }
}
```

- [ ] **Step 5: Add the config default**

In `config/doccum.php`:

```php
    // Never under storage/: that path lives inside the container image, not on
    // the data volume, so anything written there is lost on the next rebuild.
    'runtime_config_path' => env('DOCCUM_RUNTIME_CONFIG', '/data/runtime.json'),
```

- [ ] **Step 6: Run focused, then full suite. Commit.**

---

### Task 2: Applying overrides before boot

**Files:**
- Create: `app/Providers/RuntimeConfigServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Test: `tests/Feature/RuntimeConfigProviderTest.php`

**Interfaces:**
- Produces: database and `documents` disk config taken from the runtime file when present; `RuntimeConfigServiceProvider::hasError(): bool` and `::error(): ?string` for the guard in Task 6.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Providers\RuntimeConfigServiceProvider;
use App\Support\RuntimeConfig;

beforeEach(function () {
    $this->file = sys_get_temp_dir().'/doccum-runtime-'.uniqid().'.json';
    config()->set('doccum.runtime_config_path', $this->file);
});

afterEach(fn () => @unlink($this->file));

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
```

Throwing from `register()` would produce an unrecoverable white screen with no
route to fix it. Recording the error lets the guard in Task 5 explain it.

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Write the provider**

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\RuntimeConfigUnreadable;
use App\Support\RuntimeConfig;
use Illuminate\Support\ServiceProvider;

/**
 * Applies the installer's runtime overrides before anything resolves a
 * database connection or a disk.
 *
 * Registered FIRST in bootstrap/providers.php. Configuration is already loaded
 * by the time any provider registers, and connections are resolved lazily, so
 * register() is early enough -- and is the last moment that is still early
 * enough. See spec §10a.
 */
class RuntimeConfigServiceProvider extends ServiceProvider
{
    private static ?string $error = null;

    public static function hasError(): bool
    {
        return self::$error !== null;
    }

    public static function error(): ?string
    {
        return self::$error;
    }

    public function register(): void
    {
        $this->applyOverrides();
    }

    public function applyOverrides(): void
    {
        self::$error = null;

        try {
            $config = RuntimeConfig::read();
        } catch (RuntimeConfigUnreadable $e) {
            // Never fall back to environment defaults here: an empty database
            // would look like a fresh install and invite a reinstall over live
            // data. Task 6's guard turns this into a readable page.
            self::$error = $e->getMessage();

            return;
        }

        if ($config === []) {
            return;
        }

        $this->applyDatabase($config['database'] ?? []);
    }

    /** @param  array<string, mixed>  $database */
    private function applyDatabase(array $database): void
    {
        if ($database === []) {
            return;
        }

        $connection = (string) ($database['connection'] ?? config('database.default'));
        config()->set('database.default', $connection);

        foreach (['host', 'port', 'database', 'username', 'password', 'charset'] as $key) {
            if (array_key_exists($key, $database)) {
                config()->set("database.connections.{$connection}.{$key}", $database[$key]);
            }
        }
    }

    /**
     * Storage configuration comes from the settings table, not the file.
     *
     * Applied in boot() rather than register() because it needs the database --
     * which is safe, since nothing resolves a disk during boot. The file stays
     * limited to the one thing that genuinely cannot be read from the database:
     * how to reach the database.
     */
    public function boot(): void
    {
        if (self::hasError() || ! Schema::hasTable('settings')) {
            return;
        }

        $settings = $this->app->make(Settings::class);

        foreach (['endpoint', 'key', 'secret', 'bucket', 'region'] as $key) {
            $value = $settings->get("storage.{$key}");

            if ($value !== null) {
                config()->set("filesystems.disks.documents.{$key}", $value);
            }
        }
    }
}
```

- [ ] **Step 4: Register it first**

`bootstrap/providers.php` — `RuntimeConfigServiceProvider` must be the FIRST entry:

```php
return [
    App\Providers\RuntimeConfigServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\FortifyServiceProvider::class,
    App\Providers\DoccumServiceProvider::class,
];
```

Read the existing file and preserve every provider already listed.

- [ ] **Step 5: Run focused, then full suite. Commit.**

---

### Task 3: Encrypted settings values

**Files:**
- Modify: `app/Services/Settings.php`, `app/Models/Setting.php`
- Test: `tests/Feature/EncryptedSettingsTest.php`

**Interfaces:**
- `Settings::setSecret(string $key, string $value, ?int $userId = null): void`
- `Settings::get()` transparently decrypts a secret value
- `Settings::isSecret(string $key): bool`

Storage credentials live in the `settings` table rather than the runtime file,
so they need encryption at rest there. Only values written through `setSecret`
are encrypted, so ordinary settings stay queryable and readable.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\Settings;

it('round trips a secret', function () {
    app(Settings::class)->setSecret('storage.secret', 'a-very-secret-value');

    expect(app(Settings::class)->get('storage.secret'))->toBe('a-very-secret-value');
});

it('does not store a secret in plaintext', function () {
    app(Settings::class)->setSecret('storage.secret', 'a-very-secret-value');

    $raw = Setting::where('key', 'storage.secret')->value('value');

    expect(json_encode($raw))->not->toContain('a-very-secret-value');
});

it('marks which keys are secret', function () {
    app(Settings::class)->setSecret('storage.secret', 'x');
    app(Settings::class)->set('instance.name', 'Acme');

    expect(app(Settings::class)->isSecret('storage.secret'))->toBeTrue()
        ->and(app(Settings::class)->isSecret('instance.name'))->toBeFalse();
});

it('leaves ordinary settings readable', function () {
    app(Settings::class)->set('instance.name', 'Acme');

    expect(Setting::where('key', 'instance.name')->value('value'))->toBe('Acme');
});

it('still falls back to config for an unset secret', function () {
    expect(app(Settings::class)->get('storage.secret', 'fallback'))->toBe('fallback');
});
```

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Extend the service**

Store a secret as an envelope the reader can recognise, so decryption is driven
by the stored value rather than by a hardcoded list of key names:

```php
    private const SECRET_MARKER = '__encrypted';

    public function setSecret(string $key, string $value, ?int $userId = null): void
    {
        $this->set($key, [self::SECRET_MARKER => Crypt::encryptString($value)], $userId);
    }

    public function isSecret(string $key): bool
    {
        $stored = $this->all();

        return is_array($stored[$key] ?? null) && array_key_exists(self::SECRET_MARKER, $stored[$key]);
    }
```

And in `get()`, before returning a stored value, unwrap the envelope:

```php
        if (array_key_exists($key, $stored)) {
            $value = $stored[$key];

            if (is_array($value) && array_key_exists(self::SECRET_MARKER, $value)) {
                return Crypt::decryptString($value[self::SECRET_MARKER]);
            }

            return $value;
        }
```

Keep the existing config fallback untouched.

- [ ] **Step 4: Run focused, then full suite. Commit.**

---

### Task 4: Connection probes

**Files:**
- Create: `app/Services/ConnectionProbe.php`
- Test: `tests/Feature/ConnectionProbeTest.php`

**Interfaces:**
- `ConnectionProbe::database(array $config): ProbeResult`
- `ConnectionProbe::storage(array $config): ProbeResult`
- `ProbeResult` — readonly `bool $ok`, `?string $message`

Probing before saving is the difference between a typo failing at the form and
failing at first use, when the operator has gone.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Services\ConnectionProbe;
use Illuminate\Support\Facades\Storage;

it('accepts a working sqlite database', function () {
    $path = sys_get_temp_dir().'/probe-'.uniqid().'.sqlite';
    touch($path);

    $result = app(ConnectionProbe::class)->database([
        'connection' => 'sqlite',
        'database' => $path,
    ]);

    expect($result->ok)->toBeTrue();

    @unlink($path);
});

it('rejects an unreachable database', function () {
    $result = app(ConnectionProbe::class)->database([
        'connection' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'nope',
        'username' => 'nope',
        'password' => 'nope',
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toBeString()->not->toBeEmpty();
});

it('never repeats the password in a failure message', function () {
    $result = app(ConnectionProbe::class)->database([
        'connection' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'nope',
        'username' => 'nope',
        'password' => 'super-secret-value',
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->not->toContain('super-secret-value');
});

it('accepts storage it can write to and clean up', function () {
    Storage::fake('probe');

    $result = app(ConnectionProbe::class)->storage(['disk' => 'probe']);

    expect($result->ok)->toBeTrue()
        ->and(Storage::disk('probe')->allFiles())->toBeEmpty();
});

it('rejects storage it cannot reach', function () {
    $result = app(ConnectionProbe::class)->storage([
        'endpoint' => 'http://127.0.0.1:1',
        'key' => 'k', 'secret' => 's', 'bucket' => 'b', 'region' => 'us-east-1',
    ]);

    expect($result->ok)->toBeFalse();
});
```

The "never repeats the password" test matters: driver exceptions routinely
include the full DSN, and an installer that echoes it back puts a credential in
the browser and in any error log.

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Write the probe**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final readonly class ProbeResult
{
    public function __construct(public bool $ok, public ?string $message = null) {}

    public static function ok(): self
    {
        return new self(true);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}

class ConnectionProbe
{
    /** @param  array<string, mixed>  $config */
    public function database(array $config): ProbeResult
    {
        $name = 'doccum_probe_'.Str::random(8);
        $connection = (string) ($config['connection'] ?? 'sqlite');

        $settings = array_merge(
            config("database.connections.{$connection}", []),
            array_intersect_key($config, array_flip(['host', 'port', 'database', 'username', 'password'])),
            ['driver' => $connection],
        );

        config()->set("database.connections.{$name}", $settings);

        try {
            DB::connection($name)->getPdo();

            return ProbeResult::ok();
        } catch (Throwable $e) {
            return ProbeResult::failed($this->scrub($e->getMessage(), $config));
        } finally {
            DB::purge($name);
            config()->set("database.connections.{$name}", null);
        }
    }

    /** @param  array<string, mixed>  $config */
    public function storage(array $config): ProbeResult
    {
        $disk = (string) ($config['disk'] ?? 'doccum_probe');

        if (! isset($config['disk'])) {
            config()->set("filesystems.disks.{$disk}", array_merge(
                config('filesystems.disks.documents', []),
                array_intersect_key($config, array_flip(['endpoint', 'key', 'secret', 'bucket', 'region'])),
            ));
        }

        $probeKey = '.doccum-probe-'.Str::random(12);

        try {
            // A real write and a real delete: read-only credentials and a
            // missing bucket both pass a naive existence check.
            Storage::disk($disk)->put($probeKey, 'ok');
            Storage::disk($disk)->delete($probeKey);

            return ProbeResult::ok();
        } catch (Throwable $e) {
            return ProbeResult::failed($this->scrub($e->getMessage(), $config));
        }
    }

    /**
     * Driver exceptions routinely quote the whole DSN. Never hand a credential
     * back to the browser or to a log.
     *
     * @param  array<string, mixed>  $config
     */
    private function scrub(string $message, array $config): string
    {
        foreach (['password', 'secret', 'key'] as $field) {
            $value = $config[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $message = str_replace($value, '[redacted]', $message);
            }
        }

        return Str::limit($message, 300);
    }
}
```

`ProbeResult` shares the file deliberately — it is a value object of this
service and has no life of its own.

- [ ] **Step 4: Run focused, then full suite. Commit.**

---

### Task 5: The installer wizard

**Files:**
- Modify: `app/Livewire/Setup/FirstRun.php`, `resources/views/livewire/setup/first-run.blade.php`
- Test: `tests/Feature/InstallerTest.php`

**Interfaces:**
- `FirstRun` gains `public int $step` (1 database, 2 storage, 3 admin), `testDatabase()`, `testStorage()`, `saveDatabase()`, `saveStorage()`, and the existing `submit()` for the admin.

Order matters: the database is chosen and migrated BEFORE the admin is created,
so the admin lands in the database the operator picked rather than in the
bootstrap SQLite.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Livewire\Setup\FirstRun;
use App\Models\User;
use App\Support\RuntimeConfig;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->file = sys_get_temp_dir().'/doccum-runtime-'.uniqid().'.json';
    config()->set('doccum.runtime_config_path', $this->file);
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);
});

afterEach(fn () => @unlink($this->file));

it('starts on the database step', function () {
    Livewire::test(FirstRun::class)->assertSet('step', 1);
});

it('refuses to advance past a database it cannot reach', function () {
    Livewire::test(FirstRun::class)
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
        ->set('db_connection', 'sqlite')
        ->set('db_database', config('database.connections.sqlite.database'))
        ->call('saveDatabase')
        ->assertHasNoErrors()
        ->assertSet('step', 2);
});

it('lets the operator keep the default storage', function () {
    Storage::fake('documents');

    Livewire::test(FirstRun::class)
        ->set('step', 2)
        ->call('skipStorage')
        ->assertSet('step', 3);
});

it('refuses storage it cannot write to', function () {
    Livewire::test(FirstRun::class)
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

    Livewire::test(FirstRun::class)
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

    expect(app(App\Services\Settings::class)->get('storage.secret'))->toBe('a-very-secret-string');
});
```

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Extend the component**

Keep the existing admin logic in `submit()` untouched; add the two earlier steps
around it. On `saveDatabase()`: probe, and only on success write the `database` block to
`RuntimeConfig`, apply it to live config, purge the connection, and run
`Artisan::call('migrate', ['--force' => true])` so the chosen database has the
schema before anything is written into it. On a probe failure, `addError` on the
relevant field and do not advance.

On `saveStorage()`: probe, then write each value through `Settings` — using
`setSecret()` for `storage.secret` and plain `set()` for the rest. Storage never
touches the runtime file. `skipStorage()` advances without writing anything.

- [ ] **Step 4: Extend the view**

Three panels switched on `$step`, each with its own submit, mirroring the
existing Flux field markup. Password and secret fields use `type="password"`.
Show a step indicator. Read the current view first and extend it rather than
replacing it.

- [ ] **Step 5: Run focused, then full suite. Commit.**

---

### Task 6: The lockout guard and config commands

**Files:**
- Create: `app/Console/Commands/ConfigShow.php`, `app/Console/Commands/ConfigReset.php`
- Modify: `app/Http/Middleware/RequireInstanceSetup.php`
- Test: `tests/Feature/RuntimeConfigGuardTest.php`, `tests/Feature/ConfigCommandsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Providers\RuntimeConfigServiceProvider;
use App\Support\RuntimeConfig;

beforeEach(function () {
    $this->file = sys_get_temp_dir().'/doccum-runtime-'.uniqid().'.json';
    config()->set('doccum.runtime_config_path', $this->file);
});

afterEach(fn () => @unlink($this->file));

it('refuses to run the installer when config is present but unreadable', function () {
    file_put_contents($this->file, '{ not json');
    (new RuntimeConfigServiceProvider(app()))->applyOverrides();

    $this->get(route('setup'))
        ->assertServiceUnavailable()
        ->assertSee('APP_KEY', false);
});

it('masks secrets when showing config', function () {
    RuntimeConfig::write([
        'database' => ['host' => 'db.internal', 'password' => 'super-secret-value'],
        'storage' => ['bucket' => 'papers', 'secret' => 'another-secret'],
    ]);

    $this->artisan('doccum:config:show')
        ->expectsOutputToContain('db.internal')
        ->expectsOutputToContain('papers')
        ->doesntExpectOutputToContain('super-secret-value')
        ->doesntExpectOutputToContain('another-secret')
        ->assertSuccessful();
});

it('resets the runtime config', function () {
    RuntimeConfig::write(['storage' => ['bucket' => 'papers']]);

    $this->artisan('doccum:config:reset')->assertSuccessful();

    expect(RuntimeConfig::exists())->toBeFalse();
});
```

`doccum:config:reset` is the documented way out of a lockout, so it must work
without the app being able to read the file.

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Extend `RequireInstanceSetup`**

Before any other check:

```php
        if (RuntimeConfigServiceProvider::hasError()) {
            abort(503, RuntimeConfigServiceProvider::error());
        }
```

This stops the installer running against what looks like a fresh database when
in fact a configured one exists but cannot be read.

- [ ] **Step 4: Write both commands**

`doccum:config:show` prints each key, masking anything whose key contains
`password`, `secret`, or `key`. `doccum:config:reset` confirms, then calls
`RuntimeConfig::forget()` — and must not call `read()` first, or a corrupt file
could not be cleared.

- [ ] **Step 5: Run focused, then full suite. Commit.**

---

## Done when

- A fresh `docker compose up` offers database, storage, then admin, and needs no file edited anywhere.
- Each step probes for real before saving; a bad credential fails at the form.
- The runtime file is encrypted, `0600`, on the data volume, and holds ONLY the database block.
- Storage credentials live in the `settings` table with the secret encrypted at rest.
- No probe failure or exception message ever echoes a password or secret.
- A present-but-unreadable config serves a 503 explaining `APP_KEY` and refuses to reinstall.
- `doccum:config:show` masks secrets; `doccum:config:reset` clears even a corrupt file.
- `php artisan test` green; the container boots clean from empty volumes.

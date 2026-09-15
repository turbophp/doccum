# doccum Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up a booting, containerised Laravel application with a tested directory tree, file/version data model, and settings layer — the foundation every later plan builds on.

**Architecture:** A stock Laravel 13 + Livewire 4 application. Domain logic lives in `app/Actions`, `app/Services`, and `app/Support`; nothing under `vendor/` is ever modified. The directory tree uses a materialised path (`/1/5/9/`) so subtree and ancestor queries behave identically on SQLite, MySQL, and Postgres. Files carry a denormalised creation period that drives object-key layout and, later, bulk archive and purge.

**Tech Stack:** PHP 8.4, Laravel 13, Livewire 4 (class components), Flux UI free, Tailwind 4, Pest 4, SQLite (default), MinIO via the S3 driver, Docker with `serversideup/php:8.4-frankenphp-bookworm`.

**Spec:** `docs/superpowers/specs/2026-09-15-doccum-design.md`

## Global Constraints

Every task's requirements implicitly include these.

- **Never edit anything under `vendor/`.** No patches, no forks. (Spec §3, Upgrade seam.)
- **Framework classes are consumed, not subclassed**, except at documented extension points: service providers, custom casts, validation rules, middleware, policies, Blade/Livewire components.
- **One registration point.** Every macro, binding, morph map, and policy registration goes in `app/Providers/DoccumServiceProvider.php`.
- **Publish only config files we actually modify.** Product settings live in `config/doccum.php`, never scattered into published framework configs.
- **Framework tables are only added to.** Never edit a framework migration; `users` gains columns via our own additive migration.
- **Zero-configuration boot.** `docker compose up` on a clean checkout must work with no command, no file edit, and no external account.
- **No application code names a container.** Every infrastructure boundary is read from environment variables.
- PHP 8.4, `declare(strict_types=1);` at the top of every PHP file we author.
- Pest for all tests. Every task ends with a green suite and a commit.
- Composer constraints stay on caret ranges.

---

## File Structure

| Path | Responsibility |
|---|---|
| `config/doccum.php` | Product defaults: version, settings defaults, storage, extraction, retention. |
| `app/Providers/DoccumServiceProvider.php` | The single registration point for the whole application. |
| `app/Models/Setting.php` | Eloquent row for one operator-overridden setting. |
| `app/Services/Settings.php` | Reads settings through a cache, falls back to `config/doccum.php`. |
| `app/Models/Directory.php` | Tree node; owns materialised-path maintenance and subtree queries. |
| `app/Models/File.php` | Stable file identity; derives its creation period. |
| `app/Models/FileVersion.php` | Immutable record of one uploaded object. |
| `app/Actions/Directories/MoveDirectory.php` | Reparents a directory and rewrites its subtree. |
| `app/Exceptions/CannotMoveDirectoryIntoItself.php` | Guard failure for an illegal move. |
| `app/Support/ObjectKey.php` | Builds and parses MinIO object keys. Pure, no I/O. |
| `Dockerfile` | Three-stage build on `serversideup/php`, adding poppler and tesseract. |
| `compose.yaml` | Default stack: app, three workers, MinIO, MinIO init. |

`ObjectKey` is deliberately pure and dependency-free so the archive/purge plan can reason about prefixes without touching storage, and `Settings` is the only thing that ever reads the `settings` table.

---

### Task 1: Scaffold the application

**Files:**
- Create: the full Laravel skeleton at the repository root
- Preserve: `docs/` (already present — the spec lives there)

**Interfaces:**
- Consumes: nothing
- Produces: a booting Laravel 13 app with Pest, Livewire class components, and SQLite; `php artisan test` green

- [ ] **Step 1: Update the Laravel installer**

The installed version is 5.25.3; 5.32.0 is current and the older one predates some flags used below.

```bash
composer global update laravel/installer
laravel --version   # expect >= 5.32.0
```

- [ ] **Step 2: Scaffold into a temporary directory**

The project root already contains `docs/`, and `laravel new` refuses a non-empty target. Scaffold outside it, then move the files in.

```bash
cd /tmp && rm -rf doccum-scaffold
laravel new doccum-scaffold \
  --livewire \
  --livewire-class-components \
  --pest \
  --database=sqlite \
  --npm
```

Class components rather than Volt: the file browser components in later plans are substantial, and class components are markedly easier to unit-test with Livewire's testing helpers.

- [ ] **Step 3: Move the scaffold into the project root, preserving `docs/`**

```bash
cd /tmp/doccum-scaffold
shopt -s dotglob
for f in *; do
  [ "$f" = "docs" ] && continue
  cp -r "$f" /home/abdullak/projects/doccum/
done
cd /home/abdullak/projects/doccum
ls -a   # expect: app/ bootstrap/ config/ database/ docs/ public/ resources/ routes/ tests/ vendor/ artisan composer.json
```

- [ ] **Step 4: Verify the suite is green before changing anything**

```bash
php artisan test
```

Expected: PASS. This is the baseline — if the scaffold is not green, stop and fix it before continuing, because every later task's "run the tests" step assumes it.

- [ ] **Step 5: Initialise git and commit the scaffold**

```bash
cd /home/abdullak/projects/doccum
git init -b main
git add -A
git commit -m "chore: scaffold Laravel 13 with Livewire starter kit and Pest"
```

The spec and this plan are already in `docs/` and are included in this commit.

---

### Task 2: Product configuration, MinIO disk, and licence

**Files:**
- Create: `config/doccum.php`, `app/Providers/DoccumServiceProvider.php`, `LICENSE`
- Modify: `config/filesystems.php`, `.env.example`, `bootstrap/providers.php`
- Test: `tests/Feature/ConfigurationTest.php`

**Interfaces:**
- Consumes: Task 1's skeleton
- Produces: `config('doccum.*')` defaults; a `documents` filesystem disk resolving to MinIO; `DoccumServiceProvider` registered as the single registration point

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

it('ships product defaults in config', function () {
    expect(config('doccum.version'))->toBeString()
        ->and(config('doccum.settings.auth.public_signup'))->toBeFalse()
        ->and(config('doccum.settings.directories.auto_home'))->toBeTrue()
        ->and(config('doccum.settings.instance.name'))->toBe('doccum')
        ->and(config('doccum.extraction.scanned_pdf_threshold'))->toBe(100)
        ->and(config('doccum.retention.auto_purge'))->toBeFalse();
});

it('defines a documents disk backed by s3', function () {
    expect(config('filesystems.disks.documents.driver'))->toBe('s3')
        ->and(config('filesystems.disks.documents.use_path_style_endpoint'))->toBeTrue();

    Storage::fake('documents');
    Storage::disk('documents')->put('probe.txt', 'ok');
    expect(Storage::disk('documents')->get('probe.txt'))->toBe('ok');
});

it('registers the single registration point', function () {
    expect(app()->getProviders(App\Providers\DoccumServiceProvider::class))->not->toBeEmpty();
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php artisan test --filter=ConfigurationTest
```

Expected: FAIL — `config('doccum.version')` is null and `App\Providers\DoccumServiceProvider` does not exist.

- [ ] **Step 3: Create `config/doccum.php`**

```php
<?php

declare(strict_types=1);

return [
    'version' => env('DOCCUM_VERSION', '0.1.0'),

    // Defaults for operator-editable settings. Rows in the `settings` table
    // override these at runtime; see app/Services/Settings.php. An empty
    // settings table must leave the application fully functional.
    //
    // NESTED, not flat dotted keys. Arr::get() tests the full path as one
    // literal key, then explodes on "." and walks segment by segment — it
    // never recombines segments. So a literal 'auth.public_signup' key is
    // unreachable via config('doccum.settings.auth.public_signup').
    // Settings::get('auth.public_signup') resolves against this nesting,
    // while `settings` table rows keep the flat dotted key as their `key`.
    'settings' => [
        'instance' => [
            'name' => 'doccum',
        ],
        'auth' => [
            'public_signup' => false,
            'default_role' => 'member',
        ],
        'directories' => [
            'auto_home' => true,
        ],
    ],

    'storage' => [
        'disk' => env('DOCCUM_DISK', 'documents'),
        'staging_prefix' => 'uploads',
        'files_prefix' => 'files',
    ],

    'extraction' => [
        // Below this many characters, a PDF is treated as scanned and sent to OCR.
        'scanned_pdf_threshold' => 100,
        'ocr_page_limit' => 50,
        'timeout_seconds' => 600,
    ],

    'retention' => [
        'purge_after_years' => null,
        'auto_purge' => false,
    ],
];
```

- [ ] **Step 4: Add the `documents` disk**

In `config/filesystems.php`, add to the `disks` array:

```php
'documents' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    'bucket' => env('AWS_BUCKET', 'doccum'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => true,
    'throw' => true,
],
```

`use_path_style_endpoint` is hardcoded `true` rather than read from env: MinIO requires it, and every S3-compatible service accepts it.

- [ ] **Step 5: Create the registration point**

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * The single registration point for doccum.
 *
 * Every macro, container binding, morph map, policy registration, and Blade
 * directive we add lives here, so auditing what this application changed about
 * Laravel during a framework upgrade is reading one file. See spec §3.
 */
class DoccumServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
```

Register it in `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\DoccumServiceProvider::class,
];
```

- [ ] **Step 6: Add MinIO variables to `.env.example`**

Append:

```dotenv
# Storage — local MinIO by default. Point these at S3 or a remote MinIO to
# detach storage from the compose stack; no code change is required.
FILESYSTEM_DISK=documents
AWS_ACCESS_KEY_ID=doccum
AWS_SECRET_ACCESS_KEY=doccum-secret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=doccum
AWS_ENDPOINT=http://minio:9000

DOCCUM_VERSION=0.1.0
```

- [ ] **Step 7: Add the MIT licence**

Create `LICENSE` with the standard MIT text, `Copyright (c) 2026 doccum contributors`.

- [ ] **Step 8: Run the tests**

```bash
php artisan test --filter=ConfigurationTest
```

Expected: PASS, 3 tests.

- [ ] **Step 9: Commit**

```bash
git add config/doccum.php config/filesystems.php app/Providers/DoccumServiceProvider.php \
        bootstrap/providers.php .env.example LICENSE tests/Feature/ConfigurationTest.php
git commit -m "feat: add product config, MinIO documents disk, and registration point"
```

---

### Task 3: Container image and zero-config compose

**Files:**
- Create: `Dockerfile`, `compose.yaml`, `.dockerignore`
- Test: manual verification steps below (this task's deliverable is a booting container, not a unit)

**Interfaces:**
- Consumes: Task 2's `.env.example` variable names
- Produces: `docker compose up` serving the app on `http://localhost:8080` with MinIO provisioned

- [ ] **Step 1: Write the `.dockerignore`**

```
.git
node_modules
vendor
storage/logs/*
storage/framework/cache/*
database/*.sqlite
docs
tests
.env
```

- [ ] **Step 2: Write the `Dockerfile`**

```dockerfile
# syntax=docker/dockerfile:1

# ---- base: runtime + the binaries extraction needs (spec §7) ----
FROM serversideup/php:8.4-frankenphp-bookworm AS base
ARG WITH_OFFICE=false
USER root
RUN apt-get update \
 && apt-get install -y --no-install-recommends poppler-utils tesseract-ocr tesseract-ocr-eng \
 && if [ "$WITH_OFFICE" = "true" ]; then \
      apt-get install -y --no-install-recommends libreoffice-core libreoffice-writer libreoffice-calc; \
    fi \
 && rm -rf /var/lib/apt/lists/*
RUN install-php-extensions pdo_pgsql pdo_mysql intl gd zip bcmath exif
# Created here so the named volume inherits this ownership on first use;
# Docker seeds an empty named volume from the image, permissions included.
RUN mkdir -p /data && chown www-data:www-data /data
USER www-data

# ---- vendor ----
FROM base AS vendor
WORKDIR /app
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ---- assets ----
FROM node:24-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

# ---- app ----
FROM base AS app
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev --no-interaction

ENV AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    AUTORUN_LARAVEL_MIGRATION_ISOLATION=true \
    AUTORUN_LARAVEL_STORAGE_LINK=true \
    PHP_OPCACHE_ENABLE=1
```

`tesseract-ocr` and `poppler-utils` are installed here rather than on the host precisely so extraction works identically for every self-hoster. LibreOffice is opt-in because it adds roughly 500 MB for legacy `.doc` support alone.

- [ ] **Step 3: Write `compose.yaml`**

```yaml
name: doccum

x-app: &app
  build:
    context: .
    args:
      WITH_OFFICE: "false"
  environment: &app-env
    APP_ENV: production
    APP_DEBUG: "false"
    APP_URL: http://localhost:8080
    DB_CONNECTION: sqlite
    DB_DATABASE: /data/doccum.sqlite
    # Queue and cache on database/file drivers: the default stack needs no Redis.
    QUEUE_CONNECTION: database
    CACHE_STORE: database
    SESSION_DRIVER: database
    FILESYSTEM_DISK: documents
    AWS_ACCESS_KEY_ID: doccum
    AWS_SECRET_ACCESS_KEY: doccum-secret
    AWS_DEFAULT_REGION: us-east-1
    AWS_BUCKET: doccum
    AWS_ENDPOINT: http://minio:9000
  volumes:
    # Mounted at /data, NOT at /var/www/html/database — a volume there would
    # shadow database/migrations and every migration would vanish.
    - db:/data
  restart: unless-stopped

services:
  app:
    <<: *app
    environment:
      <<: *app-env
      AUTORUN_ENABLED: "true"
    ports:
      - "8080:8080"

  worker:
    <<: *app
    environment:
      <<: *app-env
      AUTORUN_ENABLED: "false"
    command: ["php", "artisan", "queue:work", "--queue=default", "--tries=3", "--max-time=3600"]

  worker-ingest:
    <<: *app
    environment:
      <<: *app-env
      AUTORUN_ENABLED: "false"
    command: ["php", "artisan", "queue:work", "--queue=ingest", "--tries=2", "--timeout=900", "--max-time=3600"]

  scheduler:
    <<: *app
    environment:
      <<: *app-env
      AUTORUN_ENABLED: "false"
    command: ["php", "artisan", "schedule:work"]

  minio:
    image: minio/minio:latest
    command: server /data --console-address ":9001"
    environment:
      MINIO_ROOT_USER: doccum
      MINIO_ROOT_PASSWORD: doccum-secret
    volumes:
      - minio:/data
    ports:
      - "9000:9000"
      - "9001:9001"
    restart: unless-stopped

  minio-init:
    image: minio/mc:latest
    entrypoint: >
      /bin/sh -c "
      until mc alias set local http://minio:9000 doccum doccum-secret; do sleep 1; done;
      mc mb --ignore-existing local/doccum;
      exit 0;
      "
    restart: "no"

  postgres:
    image: postgres:17-alpine
    profiles: ["db"]
    environment:
      POSTGRES_DB: doccum
      POSTGRES_USER: doccum
      POSTGRES_PASSWORD: doccum
    volumes:
      - pg:/var/lib/postgresql/data

  redis:
    image: redis:7-alpine
    profiles: ["cache"]
    volumes:
      - redis:/data

volumes:
  db:
  minio:
  pg:
  redis:
```

Only `app` runs the boot automation; the workers and scheduler set `AUTORUN_ENABLED=false` so four containers do not race to run migrations against the same SQLite file.

No service declares `depends_on`. Nothing touches storage or cache during boot, so each container starts independently — which is what makes "point storage at a remote" an environment change rather than a compose-file edit. `minio-init` retries until MinIO answers, so it needs no ordering either.

- [ ] **Step 4: Validate the compose file parses**

```bash
docker compose config >/dev/null && echo "compose OK"
```

Expected: `compose OK`.

- [ ] **Step 5: Build and boot**

```bash
docker compose up -d --build
docker compose ps
```

Expected: `app`, `worker`, `worker-ingest`, `scheduler`, `minio` running; `minio-init` exited 0.

- [ ] **Step 6: Verify the app answers and the bucket exists**

```bash
curl -si http://localhost:8080 | head -1
docker compose run --rm minio-init sh -c "mc alias set local http://minio:9000 doccum doccum-secret && mc ls local/"
```

Expected: an HTTP status line (200 or a redirect to `/login`), and `doccum/` in the bucket listing.

- [ ] **Step 7: Verify migrations ran automatically**

```bash
docker compose exec app php artisan migrate:status | head -5
```

Expected: framework migrations listed as `Ran`. This is the zero-configuration promise — nobody ran `migrate`.

- [ ] **Step 8: Tear down and commit**

```bash
docker compose down
git add Dockerfile compose.yaml .dockerignore
git commit -m "feat: add container image and zero-config compose stack"
```

---

### Task 4: Settings table and service

**Files:**
- Create: `database/migrations/xxxx_create_settings_table.php`, `app/Models/Setting.php`, `app/Services/Settings.php`
- Test: `tests/Feature/SettingsTest.php`

**Interfaces:**
- Consumes: `config('doccum.settings.*')` from Task 2
- Produces:
  - `App\Services\Settings::get(string $key, mixed $default = null): mixed`
  - `App\Services\Settings::set(string $key, mixed $value, ?int $userId = null): void`
  - `App\Services\Settings::all(): array`
  - `App\Services\Settings::flush(): void`

Later plans read every operator-editable value through this service. Nothing else may query the `settings` table.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\Settings;

it('falls back to the config default when no row exists', function () {
    expect(app(Settings::class)->get('auth.public_signup'))->toBeFalse()
        ->and(app(Settings::class)->get('instance.name'))->toBe('doccum');
});

it('prefers a stored row over the config default', function () {
    Setting::create(['key' => 'auth.public_signup', 'value' => true]);
    app(Settings::class)->flush();

    expect(app(Settings::class)->get('auth.public_signup'))->toBeTrue();
});

it('persists a value and invalidates the cache', function () {
    $settings = app(Settings::class);

    expect($settings->get('instance.name'))->toBe('doccum');
    $settings->set('instance.name', 'Acme Docs');

    expect($settings->get('instance.name'))->toBe('Acme Docs')
        ->and(Setting::where('key', 'instance.name')->value('value'))->toBe('Acme Docs');
});

it('returns the supplied default for an unknown key', function () {
    expect(app(Settings::class)->get('nope.not.here', 'fallback'))->toBe('fallback');
});

it('boots correctly with an empty settings table', function () {
    expect(Setting::count())->toBe(0)
        ->and(app(Settings::class)->get('directories.auto_home'))->toBeTrue();
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php artisan test --filter=SettingsTest
```

Expected: FAIL — `App\Models\Setting` does not exist.

- [ ] **Step 3: Create the migration**

```bash
php artisan make:migration create_settings_table
```

```php
Schema::create('settings', function (Blueprint $table) {
    $table->id();
    $table->string('key', 128)->unique();
    $table->json('value')->nullable();
    $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
});
```

- [ ] **Step 4: Create the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
```

- [ ] **Step 5: Create the service**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Operator-editable settings, layered over config defaults.
 *
 * config/doccum.php ships the defaults in code; rows in `settings` override
 * them at runtime. A fresh install with an empty table is fully functional,
 * which the zero-configuration boot depends on. See spec §4.
 */
class Settings
{
    private const CACHE_KEY = 'doccum.settings';

    public function __construct(
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $stored = $this->all();

        if (array_key_exists($key, $stored)) {
            return $stored[$key];
        }

        return $this->config->get("doccum.settings.{$key}", $default);
    }

    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $userId],
        );

        $this->flush();
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->cache->rememberForever(
            self::CACHE_KEY,
            fn (): array => Setting::query()->pluck('value', 'key')->all(),
        );
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
```

- [ ] **Step 6: Run the tests**

```bash
php artisan test --filter=SettingsTest
```

Expected: PASS, 5 tests.

- [ ] **Step 7: Commit**

```bash
git add database/migrations app/Models/Setting.php app/Services/Settings.php tests/Feature/SettingsTest.php
git commit -m "feat: add settings table layered over config defaults"
```

---

### Task 5: Username on users

**Files:**
- Create: `database/migrations/xxxx_add_username_to_users_table.php`
- Modify: `app/Models/User.php`, `database/factories/UserFactory.php`
- Test: `tests/Feature/UsernameTest.php`

**Interfaces:**
- Consumes: the framework `users` table
- Produces: `users.username` (unique, required) and `User::$fillable` including `username`; later plans name each user's home directory after it

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;

it('stores a username on a user', function () {
    $user = User::factory()->create(['username' => 'ada']);

    expect($user->fresh()->username)->toBe('ada');
});

it('refuses a duplicate username', function () {
    User::factory()->create(['username' => 'ada']);

    expect(fn () => User::factory()->create(['username' => 'ada']))
        ->toThrow(QueryException::class);
});

it('generates a unique username from the factory', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    expect($a->username)->not->toBe($b->username)
        ->and($a->username)->toMatch('/^[a-z0-9._-]+$/');
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php artisan test --filter=UsernameTest
```

Expected: FAIL — no `username` column.

- [ ] **Step 3: Create the additive migration**

Per the upgrade seam, the framework's own users migration is never edited.

```bash
php artisan make:migration add_username_to_users_table
```

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('username', 64)->unique()->after('name');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropUnique(['username']);
        $table->dropColumn('username');
    });
}
```

- [ ] **Step 4: Add `username` to the model's fillable**

In `app/Models/User.php`, add `'username'` to `$fillable`.

- [ ] **Step 5: Give the factory a unique username**

In `database/factories/UserFactory.php`, add to the returned array:

```php
'username' => fake()->unique()->userName(),
```

If `userName()` produces characters outside `[a-z0-9._-]`, normalise it:

```php
'username' => Str::lower(preg_replace('/[^a-zA-Z0-9._-]/', '', fake()->unique()->userName())),
```

Add `use Illuminate\Support\Str;` to the factory's imports if it is not already there.

- [ ] **Step 6: Run the focused tests**

```bash
php artisan test --filter=UsernameTest
```

Expected: PASS, 3 tests.

- [ ] **Step 7: Make registration supply a username**

A required column with no default breaks the starter kit's registration flow:
`CreateNewUser` builds the user from `name`, `email`, `password` only, so
`php artisan test` now fails `RegistrationTest`. The spec requires a username at
signup (§10), so registration is where this gets fixed.

Add `usernameRules()` to `app/Concerns/ProfileValidationRules.php`:

```php
    /**
     * Get the validation rules used to validate usernames.
     *
     * Deliberately NOT included in profileRules(): the profile update form does
     * not submit a username, and adding a required rule there would break it.
     * A username namespaces the user's home directory, so its character set is
     * constrained to what is safe as a directory name. See spec §4.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function usernameRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'min:2',
            'max:64',
            'regex:/^[a-z0-9._-]+$/',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
```

Wire it into `app/Actions/Fortify/CreateNewUser.php`:

```php
        Validator::make($input, [
            ...$this->profileRules(),
            'username' => $this->usernameRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'username' => $input['username'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);
```

Add the field to `resources/views/livewire/auth/register.blade.php`, directly
after the Name input:

```blade
            <!-- Username -->
            <flux:input
                name="username"
                :label="__('Username')"
                :value="old('username')"
                type="text"
                required
                autocomplete="username"
                :placeholder="__('username')"
                :description="__('Lowercase letters, numbers, dots, dashes and underscores. Names your personal folder.')"
            />
```

- [ ] **Step 8: Cover registration with tests**

Update the starter kit's `tests/Feature/Auth/RegistrationTest.php` so its
registration payload includes a `username`, then add to
`tests/Feature/UsernameTest.php`:

```php
it('requires a username to register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('username');
    expect(User::where('email', 'ada@example.com')->exists())->toBeFalse();
});

it('rejects a username that is already taken', function () {
    User::factory()->create(['username' => 'ada']);

    $response = $this->post(route('register.store'), [
        'name' => 'Someone Else',
        'username' => 'ada',
        'email' => 'else@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('username');
});

it('rejects a username with characters that are unsafe in a folder name', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'username' => 'Ada Lovelace!',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('username');
});

it('registers a user with a valid username', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'username' => 'ada.lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    expect(User::where('email', 'ada@example.com')->value('username'))->toBe('ada.lovelace');
});
```

Match the existing `RegistrationTest` payload's field names exactly (including
whether it sends `password_confirmation`) rather than assuming.

- [ ] **Step 9: Run the full suite**

```bash
php artisan test
```

Expected: PASS, with no failures in `RegistrationTest`.

- [ ] **Step 10: Commit**

```bash
git add database/migrations app/Models/User.php database/factories/UserFactory.php \
        app/Concerns/ProfileValidationRules.php app/Actions/Fortify/CreateNewUser.php \
        resources/views/livewire/auth/register.blade.php \
        tests/Feature/UsernameTest.php tests/Feature/Auth/RegistrationTest.php
git commit -m "feat: add required unique username to users and registration"
```

---

### Task 6: Directories table, model, and materialised path

**Files:**
- Create: `database/migrations/xxxx_create_directories_table.php`, `app/Models/Directory.php`, `database/factories/DirectoryFactory.php`
- Test: `tests/Feature/DirectoryPathTest.php`

**Interfaces:**
- Consumes: `users` from Task 5
- Produces:
  - `Directory` with `parent_id`, `name`, `path`, `depth`, `home_user_id`, `created_by`, soft deletes
  - `Directory::syncPath(): void`
  - `Directory::depthFor(string $path): int`
  - `Directory::parent()` / `Directory::children()` relations

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Directory;

it('gives a root directory a path of its own id', function () {
    $dir = Directory::factory()->create();

    expect($dir->fresh()->path)->toBe("/{$dir->id}/")
        ->and($dir->fresh()->depth)->toBe(0);
});

it('nests a child path beneath its parent', function () {
    $parent = Directory::factory()->create();
    $child = Directory::factory()->for($parent, 'parent')->create();

    expect($child->fresh()->path)->toBe("/{$parent->id}/{$child->id}/")
        ->and($child->fresh()->depth)->toBe(1);
});

it('nests a grandchild two levels deep', function () {
    $root = Directory::factory()->create();
    $mid = Directory::factory()->for($root, 'parent')->create();
    $leaf = Directory::factory()->for($mid, 'parent')->create();

    expect($leaf->fresh()->path)->toBe("/{$root->id}/{$mid->id}/{$leaf->id}/")
        ->and($leaf->fresh()->depth)->toBe(2);
});

it('computes depth from a path', function () {
    expect(Directory::depthFor('/1/'))->toBe(0)
        ->and(Directory::depthFor('/1/5/'))->toBe(1)
        ->and(Directory::depthFor('/1/5/9/'))->toBe(2);
});

it('soft deletes a directory', function () {
    $dir = Directory::factory()->create();
    $dir->delete();

    expect(Directory::count())->toBe(0)
        ->and(Directory::withTrashed()->count())->toBe(1);
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php artisan test --filter=DirectoryPathTest
```

Expected: FAIL — `App\Models\Directory` does not exist.

- [ ] **Step 3: Create the migration**

```bash
php artisan make:migration create_directories_table
```

```php
Schema::create('directories', function (Blueprint $table) {
    $table->id();
    $table->foreignId('parent_id')->nullable()->constrained('directories')->cascadeOnDelete();
    $table->string('name', 255);
    // 512 rather than 1024: MySQL caps an index key at 3072 bytes, which is
    // 768 characters of utf8mb4. 512 characters is over 50 levels of nesting.
    $table->string('path', 512)->default('')->index();
    $table->unsignedTinyInteger('depth')->default(0);
    $table->foreignId('home_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
    $table->foreignId('created_by')->constrained('users');
    $table->timestamps();
    $table->softDeletes();

    $table->index(['parent_id', 'name']);
});
```

`(parent_id, name)` is a plain index, not unique. A unique index including `deleted_at` does not enforce anything (SQL treats NULLs as distinct, so every live row is "distinct"), and partial unique indexes are not portable to MySQL. Uniqueness among non-trashed siblings is therefore enforced in the application, with `Rule::unique()->whereNull('deleted_at')`, when directory creation lands in the next plan.

- [ ] **Step 4: Create the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A node in the document tree.
 *
 * `path` is a materialised path of ancestor ids ("/1/5/9/"). It is maintained
 * here rather than by callers so that no code path can create a node with a
 * stale path. See spec §4.
 */
class Directory extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['parent_id', 'name', 'home_user_id', 'created_by'];

    protected static function booted(): void
    {
        static::created(static fn (Directory $directory) => $directory->syncPath());
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Recompute this node's path and depth from its parent.
     *
     * Runs after insert because the path includes this row's own id, which does
     * not exist until then. saveQuietly avoids re-firing model events.
     */
    public function syncPath(): void
    {
        $parentPath = $this->parent_id
            ? (string) self::query()->whereKey($this->parent_id)->value('path')
            : '/';

        $path = $parentPath.$this->getKey().'/';

        $this->forceFill([
            'path' => $path,
            'depth' => self::depthFor($path),
        ])->saveQuietly();
    }

    public static function depthFor(string $path): int
    {
        return substr_count($path, '/') - 2;
    }
}
```

- [ ] **Step 5: Create the factory**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DirectoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'name' => fake()->unique()->words(2, true),
            'created_by' => User::factory(),
        ];
    }
}
```

- [ ] **Step 6: Run the tests**

```bash
php artisan test --filter=DirectoryPathTest
```

Expected: PASS, 5 tests.

- [ ] **Step 7: Commit**

```bash
git add database/migrations app/Models/Directory.php database/factories/DirectoryFactory.php tests/Feature/DirectoryPathTest.php
git commit -m "feat: add directory tree with materialised path"
```

---

### Task 7: Subtree and ancestor queries

**Files:**
- Modify: `app/Models/Directory.php`
- Test: `tests/Feature/DirectoryTreeQueryTest.php`

**Interfaces:**
- Consumes: `Directory` from Task 6
- Produces:
  - `Directory::descendants(): Builder` — every node beneath this one, excluding itself
  - `Directory::ancestorIds(): array<int>` — self plus every ancestor id, root first
  - `Directory::isDescendantOf(Directory $other): bool`
  - `Directory::scopeInSubtreeOf(Builder $query, Directory $root): Builder` — this node and everything beneath it

`ancestorIds()` is the exact array the search projection indexes as `ancestor_ids` (spec §8), and the access resolver expands grants with `inSubtreeOf` (spec §5). Both later plans depend on these names.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Directory;

beforeEach(function () {
    $this->root = Directory::factory()->create();
    $this->mid = Directory::factory()->for($this->root, 'parent')->create();
    $this->leaf = Directory::factory()->for($this->mid, 'parent')->create();
    $this->other = Directory::factory()->create();
});

it('lists descendants excluding itself', function () {
    $ids = $this->root->descendants()->pluck('id')->all();

    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain($this->mid->id, $this->leaf->id)
        ->and($ids)->not->toContain($this->root->id);
});

it('returns self plus ancestors, root first', function () {
    expect($this->leaf->fresh()->ancestorIds())
        ->toBe([$this->root->id, $this->mid->id, $this->leaf->id]);
});

it('returns just itself for a root directory', function () {
    expect($this->root->fresh()->ancestorIds())->toBe([$this->root->id]);
});

it('knows whether a node is beneath another', function () {
    expect($this->leaf->fresh()->isDescendantOf($this->root->fresh()))->toBeTrue()
        ->and($this->root->fresh()->isDescendantOf($this->leaf->fresh()))->toBeFalse()
        ->and($this->root->fresh()->isDescendantOf($this->root->fresh()))->toBeFalse()
        ->and($this->other->fresh()->isDescendantOf($this->root->fresh()))->toBeFalse();
});

it('scopes a query to a subtree inclusive of its root', function () {
    $ids = Directory::query()->inSubtreeOf($this->root->fresh())->pluck('id')->all();

    expect($ids)->toHaveCount(3)
        ->and($ids)->not->toContain($this->other->id);
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php artisan test --filter=DirectoryTreeQueryTest
```

Expected: FAIL — `descendants()` is undefined.

- [ ] **Step 3: Add the query methods to `Directory`**

```php
use Illuminate\Database\Eloquent\Builder;

/** Every node beneath this one, excluding itself. */
public function descendants(): Builder
{
    return static::query()
        ->where('path', 'like', $this->path.'%')
        ->whereKeyNot($this->getKey());
}

/**
 * This node's id preceded by every ancestor id, root first.
 *
 * Indexed as `ancestor_ids` by the search projection and used by the access
 * resolver, so the shape here is load-bearing for both.
 *
 * @return array<int, int>
 */
public function ancestorIds(): array
{
    return array_values(array_map(
        'intval',
        array_filter(explode('/', trim($this->path, '/')), static fn (string $s): bool => $s !== ''),
    ));
}

public function isDescendantOf(self $other): bool
{
    return $this->isNot($other) && str_starts_with($this->path, $other->path);
}

/** This node and everything beneath it. */
public function scopeInSubtreeOf(Builder $query, self $root): Builder
{
    return $query->where('path', 'like', $root->path.'%');
}
```

- [ ] **Step 4: Run the tests**

```bash
php artisan test --filter=DirectoryTreeQueryTest
```

Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Directory.php tests/Feature/DirectoryTreeQueryTest.php
git commit -m "feat: add subtree and ancestor queries to directories"
```

---

### Task 8: Moving a directory

**Files:**
- Create: `app/Actions/Directories/MoveDirectory.php`, `app/Exceptions/CannotMoveDirectoryIntoItself.php`
- Test: `tests/Feature/MoveDirectoryTest.php`

**Interfaces:**
- Consumes: `Directory` from Tasks 6-7
- Produces: `MoveDirectory::handle(Directory $directory, ?Directory $newParent): Directory` — reparents, rewrites every descendant path and depth, and throws `CannotMoveDirectoryIntoItself` on a cycle

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Actions\Directories\MoveDirectory;
use App\Exceptions\CannotMoveDirectoryIntoItself;
use App\Models\Directory;

beforeEach(function () {
    $this->a = Directory::factory()->create();
    $this->b = Directory::factory()->create();
    $this->child = Directory::factory()->for($this->a, 'parent')->create();
    $this->grandchild = Directory::factory()->for($this->child, 'parent')->create();
});

it('rewrites the moved directory path', function () {
    app(MoveDirectory::class)->handle($this->child->fresh(), $this->b->fresh());

    expect($this->child->fresh()->path)->toBe("/{$this->b->id}/{$this->child->id}/")
        ->and($this->child->fresh()->parent_id)->toBe($this->b->id)
        ->and($this->child->fresh()->depth)->toBe(1);
});

it('rewrites descendant paths and depths', function () {
    app(MoveDirectory::class)->handle($this->child->fresh(), $this->b->fresh());

    expect($this->grandchild->fresh()->path)
        ->toBe("/{$this->b->id}/{$this->child->id}/{$this->grandchild->id}/")
        ->and($this->grandchild->fresh()->depth)->toBe(2);
});

it('promotes a directory to the root', function () {
    app(MoveDirectory::class)->handle($this->child->fresh(), null);

    expect($this->child->fresh()->path)->toBe("/{$this->child->id}/")
        ->and($this->child->fresh()->depth)->toBe(0)
        ->and($this->grandchild->fresh()->path)
            ->toBe("/{$this->child->id}/{$this->grandchild->id}/")
        ->and($this->grandchild->fresh()->depth)->toBe(1);
});

it('refuses to move a directory into its own subtree', function () {
    expect(fn () => app(MoveDirectory::class)->handle($this->child->fresh(), $this->grandchild->fresh()))
        ->toThrow(CannotMoveDirectoryIntoItself::class);
});

it('refuses to move a directory into itself', function () {
    expect(fn () => app(MoveDirectory::class)->handle($this->child->fresh(), $this->child->fresh()))
        ->toThrow(CannotMoveDirectoryIntoItself::class);
});

it('leaves unrelated directories untouched', function () {
    $before = $this->a->fresh()->path;
    app(MoveDirectory::class)->handle($this->child->fresh(), $this->b->fresh());

    expect($this->a->fresh()->path)->toBe($before);
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php artisan test --filter=MoveDirectoryTest
```

Expected: FAIL — `App\Actions\Directories\MoveDirectory` does not exist.

- [ ] **Step 3: Create the exception**

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class CannotMoveDirectoryIntoItself extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A directory cannot be moved into itself or one of its own descendants.');
    }
}
```

- [ ] **Step 4: Create the action**

```php
<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Exceptions\CannotMoveDirectoryIntoItself;
use App\Models\Directory;
use Illuminate\Support\Facades\DB;

class MoveDirectory
{
    public function handle(Directory $directory, ?Directory $newParent): Directory
    {
        if ($newParent !== null && ($newParent->is($directory) || $newParent->isDescendantOf($directory))) {
            throw new CannotMoveDirectoryIntoItself();
        }

        $oldPath = $directory->path;

        DB::transaction(function () use ($directory, $newParent, $oldPath): void {
            $directory->parent_id = $newParent?->getKey();
            $directory->save();
            $directory->syncPath();

            $newPath = $directory->refresh()->path;

            // Rewrite the prefix on every descendant. REPLACE is safe here
            // because $oldPath is a leading run of unique ids, so it cannot
            // recur later in any descendant path.
            DB::statement(
                'UPDATE directories SET path = REPLACE(path, ?, ?) WHERE path LIKE ? AND id <> ?',
                [$oldPath, $newPath, $oldPath.'%', $directory->getKey()],
            );

            // Recompute depth from the rewritten path. Counting separators this
            // way is the one expression that works identically on SQLite,
            // MySQL, and Postgres.
            DB::statement(
                "UPDATE directories SET depth = LENGTH(path) - LENGTH(REPLACE(path, '/', '')) - 2 WHERE path LIKE ?",
                [$newPath.'%'],
            );
        });

        return $directory->refresh();
    }
}
```

- [ ] **Step 5: Run the tests**

```bash
php artisan test --filter=MoveDirectoryTest
```

Expected: PASS, 6 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Actions app/Exceptions tests/Feature/MoveDirectoryTest.php
git commit -m "feat: move directories with subtree path rewrite and cycle guard"
```

---

### Task 9: Files and file versions

**Files:**
- Create: `database/migrations/xxxx_create_files_table.php`, `database/migrations/xxxx_create_file_versions_table.php`, `app/Models/File.php`, `app/Models/FileVersion.php`, `database/factories/FileFactory.php`, `database/factories/FileVersionFactory.php`
- Test: `tests/Feature/FileModelTest.php`

**Interfaces:**
- Consumes: `Directory` (Task 6), `User` (Task 5)
- Produces:
  - `File` with `uuid`, `directory_id`, `name`, `current_version_id`, `mime`, `size`, `checksum`, `period_year`, `period_month`, `legal_hold`, soft deletes
  - `File::directory()`, `File::versions()`, `File::currentVersion()`
  - `FileVersion` with `file_id`, `version_number`, `object_key`, `size`, `mime`, `checksum`, `uploaded_by`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\Directory;
use App\Models\File;
use App\Models\FileVersion;
use Illuminate\Database\QueryException;

it('assigns a uuid on creation', function () {
    $file = File::factory()->create();

    expect($file->uuid)->toBeString()->toHaveLength(36);
});

it('derives its period from the creation time', function () {
    $this->travelTo('2024-03-17 10:00:00');

    $file = File::factory()->create();

    expect($file->period_year)->toBe(2024)
        ->and($file->period_month)->toBe(3);
});

it('belongs to a directory', function () {
    $dir = Directory::factory()->create();
    $file = File::factory()->for($dir, 'directory')->create();

    expect($file->directory->id)->toBe($dir->id);
});

it('has many versions and resolves the current one', function () {
    $file = File::factory()->create();
    $v1 = FileVersion::factory()->for($file)->create(['version_number' => 1]);
    $v2 = FileVersion::factory()->for($file)->create(['version_number' => 2]);

    $file->update(['current_version_id' => $v2->id]);

    expect($file->fresh()->versions)->toHaveCount(2)
        ->and($file->fresh()->currentVersion->id)->toBe($v2->id)
        ->and($v1->file->id)->toBe($file->id);
});

it('refuses duplicate version numbers for one file', function () {
    $file = File::factory()->create();
    FileVersion::factory()->for($file)->create(['version_number' => 1]);

    expect(fn () => FileVersion::factory()->for($file)->create(['version_number' => 1]))
        ->toThrow(QueryException::class);
});

it('soft deletes a file', function () {
    $file = File::factory()->create();
    $file->delete();

    expect(File::count())->toBe(0)
        ->and(File::withTrashed()->count())->toBe(1);
});

it('defaults legal hold to false', function () {
    expect(File::factory()->create()->legal_hold)->toBeFalse();
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php artisan test --filter=FileModelTest
```

Expected: FAIL — `App\Models\File` does not exist.

- [ ] **Step 3: Create the `files` migration**

```php
Schema::create('files', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('directory_id')->constrained('directories')->cascadeOnDelete();
    $table->string('name', 255);
    // No FK: files and file_versions reference each other, and adding a
    // circular constraint afterwards is not portable to SQLite. The
    // relationship is enforced in the application.
    $table->unsignedBigInteger('current_version_id')->nullable();
    $table->string('mime', 191)->nullable();
    $table->unsignedBigInteger('size')->default(0);
    $table->string('checksum', 64)->nullable();
    $table->unsignedSmallInteger('period_year')->index();
    $table->unsignedTinyInteger('period_month')->index();
    $table->boolean('legal_hold')->default(false);
    $table->foreignId('created_by')->constrained('users');
    $table->timestamps();
    $table->softDeletes();

    $table->index(['directory_id', 'name']);
});
```

- [ ] **Step 4: Create the `file_versions` migration**

```php
Schema::create('file_versions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('file_id')->constrained('files')->cascadeOnDelete();
    $table->unsignedInteger('version_number');
    $table->string('object_key', 1024);
    $table->unsignedBigInteger('size');
    $table->string('mime', 191);
    $table->string('checksum', 64);
    $table->foreignId('uploaded_by')->constrained('users');
    $table->timestamp('created_at')->useCurrent();

    $table->unique(['file_id', 'version_number']);
});
```

- [ ] **Step 5: Create the `File` model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class File extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'directory_id', 'name', 'current_version_id',
        'mime', 'size', 'checksum', 'legal_hold', 'created_by',
        // Fillable so imports and tests can pin a period explicitly. The
        // booted() hook only fills them when they are still null, so a
        // supplied period always wins.
        'period_year', 'period_month',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'period_year' => 'integer',
            'period_month' => 'integer',
            'legal_hold' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (File $file): void {
            $file->uuid ??= (string) Str::orderedUuid();

            // The period is fixed at creation and never moves, so object keys
            // stay immutable for the life of the file. See spec §6.
            $at = $file->created_at ?? now();
            $file->period_year ??= (int) $at->year;
            $file->period_month ??= (int) $at->month;
        });
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FileVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FileVersion::class, 'current_version_id');
    }
}
```

- [ ] **Step 6: Create the `FileVersion` model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable record of one uploaded object. Rows are inserted and deleted,
 * never updated. See spec §4.
 */
class FileVersion extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'file_id', 'version_number', 'object_key',
        'size', 'mime', 'checksum', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'size' => 'integer',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
```

- [ ] **Step 7: Create the factories**

```php
// database/factories/FileFactory.php
public function definition(): array
{
    return [
        'directory_id' => Directory::factory(),
        'name' => fake()->unique()->words(2, true).'.pdf',
        'mime' => 'application/pdf',
        'size' => fake()->numberBetween(1_000, 5_000_000),
        'checksum' => hash('sha256', (string) fake()->unique()->uuid()),
        'created_by' => User::factory(),
    ];
}
```

```php
// database/factories/FileVersionFactory.php
public function definition(): array
{
    return [
        'file_id' => File::factory(),
        'version_number' => 1,
        'object_key' => 'files/2026/01/'.fake()->uuid().'/v1/document.pdf',
        'size' => fake()->numberBetween(1_000, 5_000_000),
        'mime' => 'application/pdf',
        'checksum' => hash('sha256', (string) fake()->unique()->uuid()),
        'uploaded_by' => User::factory(),
    ];
}
```

- [ ] **Step 8: Run the tests**

```bash
php artisan test --filter=FileModelTest
```

Expected: PASS, 7 tests.

- [ ] **Step 9: Commit**

```bash
git add database/migrations database/factories app/Models/File.php app/Models/FileVersion.php tests/Feature/FileModelTest.php
git commit -m "feat: add files and immutable file versions with period derivation"
```

---

### Task 10: Object keys

**Files:**
- Create: `app/Support/ObjectKey.php`
- Test: `tests/Unit/ObjectKeyTest.php`

**Interfaces:**
- Consumes: `File` from Task 9
- Produces:
  - `ObjectKey::forVersion(File $file, int $versionNumber, string $filename): string`
  - `ObjectKey::staging(string $uploadUuid, string $filename): string`
  - `ObjectKey::periodPrefix(int $year, ?int $month = null): string`

Pure and dependency-free: the archive/purge plan reasons about prefixes without touching storage, and the API plan builds staging keys for presigned uploads.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Models\File;
use App\Support\ObjectKey;

it('builds a version key from period, uuid, and version', function () {
    $file = File::factory()->make([
        'uuid' => '0195f0c2-8b3a-7000-9000-000000000001',
        'period_year' => 2024,
        'period_month' => 3,
    ]);

    expect(ObjectKey::forVersion($file, 2, 'Report.pdf'))
        ->toBe('files/2024/03/0195f0c2-8b3a-7000-9000-000000000001/v2/Report.pdf');
});

it('zero pads a single digit month', function () {
    $file = File::factory()->make([
        'uuid' => '0195f0c2-8b3a-7000-9000-000000000002',
        'period_year' => 2026,
        'period_month' => 9,
    ]);

    expect(ObjectKey::forVersion($file, 1, 'a.txt'))->toStartWith('files/2026/09/');
});

it('strips directory traversal from the filename', function () {
    $file = File::factory()->make([
        'uuid' => '0195f0c2-8b3a-7000-9000-000000000003',
        'period_year' => 2026,
        'period_month' => 1,
    ]);

    expect(ObjectKey::forVersion($file, 1, '../../etc/passwd'))->toEndWith('/v1/passwd')
        ->and(ObjectKey::forVersion($file, 1, 'a/b/c.txt'))->toEndWith('/v1/c.txt');
});

it('replaces characters that are awkward in object keys', function () {
    $file = File::factory()->make([
        'uuid' => '0195f0c2-8b3a-7000-9000-000000000004',
        'period_year' => 2026,
        'period_month' => 1,
    ]);

    expect(ObjectKey::forVersion($file, 1, 'in?voice#1.pdf'))->toEndWith('/v1/in_voice_1.pdf');
});

it('falls back to a placeholder for an empty filename', function () {
    $file = File::factory()->make([
        'uuid' => '0195f0c2-8b3a-7000-9000-000000000005',
        'period_year' => 2026,
        'period_month' => 1,
    ]);

    expect(ObjectKey::forVersion($file, 1, '???'))->toEndWith('/v1/file');
});

it('builds a staging key outside the files prefix', function () {
    $key = ObjectKey::staging('0195f0c2-8b3a-7000-9000-000000000006', 'Report.pdf');

    expect($key)->toBe('uploads/0195f0c2-8b3a-7000-9000-000000000006/Report.pdf')
        ->and($key)->not->toStartWith('files/');
});

it('builds period prefixes for a month and a whole year', function () {
    expect(ObjectKey::periodPrefix(2024, 3))->toBe('files/2024/03/')
        ->and(ObjectKey::periodPrefix(2024))->toBe('files/2024/');
});
```

The staging assertion is load-bearing: an abandoned upload must never land inside a period prefix, or it would corrupt the counts that period close records and confuse purge.

- [ ] **Step 2: Run it and watch it fail**

```bash
php artisan test --filter=ObjectKeyTest
```

Expected: FAIL — `App\Support\ObjectKey` does not exist.

- [ ] **Step 3: Write the class**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\File;
use Illuminate\Support\Str;

/**
 * Builds MinIO object keys.
 *
 * Every version of a file lives under the file's creation period, so purging a
 * period is a single coherent prefix operation and can never leave one version
 * deleted while another survives in a different year. See spec §6.
 *
 * Pure by design: no I/O, no container, so archive and purge can reason about
 * prefixes without touching storage.
 */
final class ObjectKey
{
    public static function forVersion(File $file, int $versionNumber, string $filename): string
    {
        return sprintf(
            'files/%04d/%02d/%s/v%d/%s',
            $file->period_year,
            $file->period_month,
            $file->uuid,
            $versionNumber,
            self::sanitise($filename),
        );
    }

    /** Staging area for presigned uploads that have not been committed yet. */
    public static function staging(string $uploadUuid, string $filename): string
    {
        return sprintf('uploads/%s/%s', $uploadUuid, self::sanitise($filename));
    }

    public static function periodPrefix(int $year, ?int $month = null): string
    {
        return $month === null
            ? sprintf('files/%04d/', $year)
            : sprintf('files/%04d/%02d/', $year, $month);
    }

    private static function sanitise(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = preg_replace('/[^\w.\- ]+/u', '_', $name) ?? '';
        $name = trim($name, " _");

        return $name === '' ? 'file' : Str::limit($name, 180, '');
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
php artisan test --filter=ObjectKeyTest
```

Expected: PASS, 7 tests.

- [ ] **Step 5: Run the whole suite**

```bash
php artisan test
```

Expected: PASS. Everything from Task 1's baseline through Task 10 is green.

- [ ] **Step 6: Commit**

```bash
git add app/Support/ObjectKey.php tests/Unit/ObjectKeyTest.php
git commit -m "feat: add period-partitioned object key builder"
```

---

## Done when

- `docker compose up` on a clean checkout serves the app with migrations already applied and the MinIO bucket created, with no manual step.
- `php artisan test` is green.
- The directory tree maintains correct paths and depths through create, nest, and move, and refuses cycles.
- Files derive an immutable creation period; object keys place every version of a file under one period prefix, and staging keys fall outside it.
- Settings resolve from the database when present and from `config/doccum.php` when not, with an empty table fully functional.
- Nothing under `vendor/` was modified and no framework migration was edited.

## Next plans

2. **Access control** — `directory_access`, `DirectoryAccess` resolver, policies, Spatie roles and seeded permissions, first-run setup screen, home directory creation, public signup toggle.
3. **Storage** — upload, versions, presigned download, trash and restore.
4. **Attributes** — definitions admin, typed values, inline editing.
5. **Extraction** — strategies, `ExtractText` job, OCR fallback.
6. **Search** — `search_documents` projection, indexer, permission-filtered UI.
7. **Archive and purge** — period rollup, commands, guards.
8. **Admin surface and application shell** — topbar, three-pane Files view.
9. **API** — Sanctum tokens, ability model, presigned upload flow.
10. **Documentation** — Scramble reference, self-hosting guide.

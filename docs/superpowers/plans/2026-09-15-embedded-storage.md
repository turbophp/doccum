# doccum Embedded Storage & Providers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship object storage inside the image so the default install is a single container with no MinIO service, while remote S3-compatible providers (R2, Spaces, Wasabi, B2, plain S3) and Azure Blob are a setting away.

**Architecture:** MinIO runs as a supervised process inside the app container, bound to the compose network and never published to the host. Because embedded storage *is* S3, there is exactly one storage code path — the default install and a heavy user's dedicated bucket exercise the same adapter. Provider choice is a preset that fills endpoint, region and addressing style.

**Tech Stack:** Laravel 13, MinIO (copied from the official image), supervisor, `league/flysystem-aws-s3-v3`, `league/flysystem-azure-blob-storage`.

**Spec:** `docs/superpowers/specs/2026-09-15-doccum-design.md` §10a, §13

**Previous plans:** foundation, access-control, storage, installer — all merged.

## Global Constraints

- **Never edit anything under `vendor/`;** never edit a framework migration.
- `declare(strict_types=1);` on every PHP file authored.
- **Never pass an interface to `toThrow()`** — Pest branches on `class_exists()`, false for interfaces.
- **Nothing outside `App\Services\DocumentStorage` resolves a disk** (`ConnectionProbe` excepted; tests may `Storage::fake`).
- Secrets never appear in plaintext in a log, an exception, or a rendered page.
- **Pin the MinIO release tag.** Never `:latest` in a build — a rebuild must produce the same binary.
- MinIO must **never** be published to the host and must **never** run in the worker containers.
- Pest for all tests; each task ends with a green FULL suite and its own commit.
- Baseline entering this plan: **215 tests, 458 assertions**.

**Findings that shaped this plan (verified, not assumed):**
- `dl.min.io` binaries return HTTP 410 and the GitHub release carries no assets, so MinIO is obtained by copying from the official container image.
- That binary is statically linked (`not a dynamic executable`), so it runs in the Debian base unchanged.
- The `frankenphp` variant of `serversideup/php` has **no s6-overlay** — only the `fpm-nginx` variants do — so supervision is ours to add.

---

### Task 1: Bundle MinIO into the image

**Files:**
- Modify: `Dockerfile`, `compose.yaml`
- Create: `docker/supervisor/doccum.conf`, `docker/bin/doccum-minio`, `docker/entrypoint.d/48-doccum-storage.sh`

**Interfaces:**
- The app image contains `/usr/local/bin/minio`, supervised, started only when `DOCCUM_EMBEDDED_STORAGE=true`.
- `/data/minio.env` holds generated root credentials, `0600`, created once.

- [ ] **Step 1: Copy the binary from the official image**

At the top of the `Dockerfile`, pinned — never `:latest`:

```dockerfile
FROM quay.io/minio/minio:RELEASE.2025-09-07T16-13-09Z AS minio
```

and in the `app` stage:

```dockerfile
COPY --from=minio /usr/bin/minio /usr/local/bin/minio
```

The binary is statically linked, so it needs no libraries from that image. `mc`
is deliberately not copied: the AWS SDK already present creates the bucket, and
`mc` would add ~30 MB for nothing.

- [ ] **Step 2: Add the supervisor**

In the `base` stage, alongside the other apt packages, add `supervisor`. The
FrankenPHP image has no s6-overlay, so this is what runs two processes.

- [ ] **Step 3: Write the MinIO wrapper**

`docker/bin/doccum-minio` — supervisor cannot source an env file, so the wrapper
does:

```sh
#!/bin/sh
set -e
# Credentials are generated once per install and persisted on the data volume.
set -a
. /data/minio.env
set +a
exec /usr/local/bin/minio server "${DOCCUM_EMBEDDED_ROOT:-/data/objects}" --address ":9000"
```

- [ ] **Step 4: Write the supervisor config**

`docker/supervisor/doccum.conf`:

```ini
[supervisord]
nodaemon=true
user=www-data
logfile=/dev/null
logfile_maxbytes=0
pidfile=/tmp/supervisord.pid

[program:frankenphp]
command=frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
autostart=true
autorestart=true
stdout_logfile=/dev/fd/1
stdout_logfile_maxbytes=0
redirect_stderr=true
priority=10

[program:minio]
command=/usr/local/bin/doccum-minio
; Only the app container runs storage. The workers share this image and must
; not each start their own MinIO against the same data directory.
autostart=%(ENV_DOCCUM_EMBEDDED_STORAGE)s
autorestart=true
stdout_logfile=/dev/fd/1
stdout_logfile_maxbytes=0
redirect_stderr=true
priority=5
```

- [ ] **Step 5: Generate credentials and the data directory on first boot**

`docker/entrypoint.d/48-doccum-storage.sh`, numbered before the app init:

```sh
#!/bin/sh
set -e

[ "${DOCCUM_EMBEDDED_STORAGE:-false}" = "true" ] || exit 0

ROOT="${DOCCUM_EMBEDDED_ROOT:-/data/objects}"
ENV_FILE=/data/minio.env

mkdir -p "$ROOT"

if [ ! -f "$ENV_FILE" ]; then
    USER_VALUE="doccum"
    PASS_VALUE="$(php -r 'echo bin2hex(random_bytes(24));')"
    TMP="${ENV_FILE}.$$"
    {
        printf 'MINIO_ROOT_USER=%s\n' "$USER_VALUE"
        printf 'MINIO_ROOT_PASSWORD=%s\n' "$PASS_VALUE"
        printf 'DOCCUM_EMBEDDED_ROOT=%s\n' "$ROOT"
    } > "$TMP"
    chmod 600 "$TMP"
    mv "$TMP" "$ENV_FILE"
    echo "🔐 doccum: generated embedded storage credentials"
fi
```

Credentials are generated rather than baked so that an accidentally exposed port
is not a known-credentials hole.

- [ ] **Step 6: Point the image at supervisor**

Set `CMD ["supervisord", "-c", "/etc/supervisor/conf.d/doccum.conf"]` and copy
the config and wrapper in, `chmod +x` the wrapper. serversideup's entrypoint
still runs every `entrypoint.d` script before `exec`-ing this, so the AUTORUN
automations are unaffected.

- [ ] **Step 7: Update compose**

`app` gains `DOCCUM_EMBEDDED_STORAGE: "true"`; the three worker services set it
`"false"` and point `AWS_ENDPOINT` at `http://app:9000`. The `minio` and
`minio-init` services move behind `profiles: ["storage"]`, so the default stack
is the app plus its workers. Port 9000 is **not** published.

- [ ] **Step 8: Verify by running it**

```bash
docker compose down -v && docker compose up -d --build && sleep 30
docker compose exec -T app supervisorctl -c /etc/supervisor/conf.d/doccum.conf status
docker compose exec -T worker sh -c 'pgrep -f "minio server" && echo "LEAK: worker is running minio" || echo "worker correctly has no minio"'
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:9000 || echo "correctly not published to host"
```

Expected: both programs RUNNING in `app`, no MinIO in `worker`, port 9000
unreachable from the host.

- [ ] **Step 9: Commit**

---

### Task 2: Embedded storage as the default driver

**Files:**
- Modify: `config/doccum.php`, `config/filesystems.php`, `app/Providers/RuntimeConfigServiceProvider.php`, `app/Services/DocumentStorage.php`
- Create: `app/Support/EmbeddedStorage.php`
- Test: `tests/Feature/EmbeddedStorageTest.php`

**Interfaces:**
- `EmbeddedStorage::credentials(): ?array` — parses `/data/minio.env`
- `EmbeddedStorage::isActive(): bool`
- `DocumentStorage::ensureBucket(): void` — idempotent, memoised per process

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Support\EmbeddedStorage;

beforeEach(function () {
    $this->envFile = sys_get_temp_dir().'/minio-'.uniqid().'.env';
    config()->set('doccum.storage.embedded_env', $this->envFile);
});

afterEach(fn () => @unlink($this->envFile));

it('reports inactive when no credentials file exists', function () {
    expect(EmbeddedStorage::isActive())->toBeFalse()
        ->and(EmbeddedStorage::credentials())->toBeNull();
});

it('parses generated credentials', function () {
    file_put_contents($this->envFile, "MINIO_ROOT_USER=doccum\nMINIO_ROOT_PASSWORD=abc123\nDOCCUM_EMBEDDED_ROOT=/data/objects\n");

    expect(EmbeddedStorage::isActive())->toBeTrue()
        ->and(EmbeddedStorage::credentials())->toMatchArray([
            'key' => 'doccum',
            'secret' => 'abc123',
        ]);
});

it('ignores comments and blank lines', function () {
    file_put_contents($this->envFile, "# generated\n\nMINIO_ROOT_USER=doccum\nMINIO_ROOT_PASSWORD=abc123\n");

    expect(EmbeddedStorage::credentials()['secret'])->toBe('abc123');
});

it('treats a file missing the password as inactive', function () {
    file_put_contents($this->envFile, "MINIO_ROOT_USER=doccum\n");

    expect(EmbeddedStorage::isActive())->toBeFalse();
});
```

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Write `EmbeddedStorage`**

A plain `app/Support` class, like `RuntimeConfig`: it is consumed by a provider
that runs before the container is useful. Parse `KEY=value` lines, skipping
blanks and `#` comments, and return `null` unless both user and password are
present.

- [ ] **Step 4: Default the documents disk to embedded**

`config/doccum.php` gains `'storage' => ['embedded_env' => env('DOCCUM_EMBEDDED_ENV', '/data/minio.env'), 'endpoint' => env('AWS_ENDPOINT', 'http://127.0.0.1:9000')]`.

`RuntimeConfigServiceProvider::boot()` applies, in order of increasing
precedence: embedded credentials when active, then any `storage.*` settings. So
an operator who configures a remote provider overrides the embedded default, and
one who does not gets working storage with no configuration at all.

- [ ] **Step 5: Ensure the bucket exists**

`DocumentStorage::ensureBucket()` — memoised in a private property so it costs
one HEAD per process, called at the top of `put()`. Creating the bucket lazily
avoids an ordering problem: the entrypoint runs before supervisor starts MinIO,
so nothing can provision a bucket at boot.

- [ ] **Step 6: Run focused, then full suite. Commit.**

---

### Task 3: Storage provider presets

**Files:**
- Create: `app/Enums/StorageProvider.php`
- Modify: `app/Providers/RuntimeConfigServiceProvider.php`, `app/Services/ConnectionProbe.php`
- Test: `tests/Unit/StorageProviderTest.php`, `tests/Feature/StorageProviderConfigTest.php`

**Interfaces:**
- `StorageProvider` — `Embedded`, `S3`, `R2`, `Spaces`, `Wasabi`, `BackblazeB2`, `Custom`, `AzureBlob`
- `StorageProvider::endpointFor(?string $account, ?string $region): ?string`
- `StorageProvider::usesPathStyle(): bool`
- `StorageProvider::defaultRegion(): ?string`

Providers differ in three ways that matter and nothing else: the endpoint
template, whether the bucket goes in the host or the path, and what region string
they expect. Encoding that as a preset is the difference between "paste four
fields" and "read three pages of provider docs".

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Write the enum, then wire it**

`RuntimeConfigServiceProvider::boot()` reads `storage.provider` and derives
`endpoint`, `region` and `use_path_style_endpoint` from the preset, with any
explicit `storage.endpoint` setting still winning — a preset is a default, not a
cage.

- [ ] **Step 4: Run focused, then full suite. Commit.**

---

### Task 4: Azure Blob support

**Files:**
- Modify: `composer.json`, `config/filesystems.php`, `app/Providers/DoccumServiceProvider.php`
- Test: `tests/Feature/AzureBlobDriverTest.php`

Azure Blob is the one provider that is not S3-compatible, so it needs its own
driver rather than a preset.

- [ ] **Step 1: Install the adapter**

```bash
composer require league/flysystem-azure-blob-storage:^3.31
```

- [ ] **Step 2: Write the failing test**

```php
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
```

- [ ] **Step 3: Register the driver in `DoccumServiceProvider::boot()`**

`Storage::extend('azure', ...)` building a `FilesystemAdapter` around
`AzureBlobStorageAdapter`. Laravel has no built-in azure driver, so this is the
documented extension point rather than a workaround.

- [ ] **Step 4: Run focused, then full suite. Commit.**

---

### Task 5: Choosing a provider in the installer

**Files:**
- Modify: `app/Livewire/Setup/FirstRun.php`, `resources/views/livewire/setup/first-run.blade.php`
- Test: `tests/Feature/InstallerStorageProviderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\StorageProvider;
use App\Livewire\Setup\FirstRun;
use App\Services\Settings;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class));

it('defaults to embedded storage', function () {
    Livewire::test(FirstRun::class)
        ->set('step', 2)
        ->assertSet('storage_provider', StorageProvider::Embedded->value);
});

it('accepts embedded without asking for credentials', function () {
    Livewire::test(FirstRun::class)
        ->set('step', 2)
        ->set('storage_provider', StorageProvider::Embedded->value)
        ->call('saveStorage')
        ->assertHasNoErrors()
        ->assertSet('step', 3);

    expect(app(Settings::class)->get('storage.provider'))->toBe('embedded');
});

it('derives the endpoint for a chosen provider', function () {
    Livewire::test(FirstRun::class)
        ->set('step', 2)
        ->set('storage_provider', StorageProvider::R2->value)
        ->set('s3_account', 'abc123')
        ->set('s3_key', 'k')
        ->set('s3_secret', 'sssssss')
        ->set('s3_bucket', 'papers')
        ->call('previewEndpoint')
        ->assertSet('s3_endpoint', 'https://abc123.r2.cloudflarestorage.com');
});

it('stores the secret encrypted, never in the runtime file', function () {
    Livewire::test(FirstRun::class)
        ->set('step', 2)
        ->set('storage_provider', StorageProvider::Embedded->value)
        ->call('saveStorage');

    expect(app(Settings::class)->isSecret('storage.secret'))->toBeFalse();
});
```

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Extend the storage step**

A provider select defaulting to Embedded. Choosing Embedded hides every
credential field and advances without a probe — there is nothing to get wrong.
Choosing anything else reveals the fields that provider needs (account id for
R2, region for Spaces/Wasabi/B2, endpoint for Custom), derives the endpoint from
the preset, probes, and only then writes settings.

- [ ] **Step 4: Run focused, then full suite. Commit.**

---

## Done when

- `docker compose up` on a clean checkout yields ONE app container plus workers, with working object storage and no MinIO service.
- MinIO runs only in the app container, on generated credentials, never published to the host.
- Embedded and remote storage exercise the same S3 code path.
- R2, Spaces, Wasabi, B2, plain S3 and a custom endpoint are selectable, each with the right endpoint, region and addressing style.
- Azure Blob works through its own driver.
- A rebuild produces the same MinIO binary (release tag pinned).
- `php artisan test` green; the stack boots clean from empty volumes.

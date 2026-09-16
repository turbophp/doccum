# Working on doccum

Self-hosted document management: directories, versioned files, governed
properties, OCR'd text, permission-filtered search. Ships as one container.

## Commands

```bash
php artisan test                      # full suite; must be green before any commit
php artisan test --filter=SomeTest    # focused
./vendor/bin/pint                     # format (--test to check)
./vendor/bin/phpstan analyse          # ~44 pre-existing findings; add none
docker compose up -d --build          # the real stack
docker run -d -v doccum:/data -p 8080:8080 doccum:local   # single container
```

The container is the product. A green suite is necessary and not sufficient —
run the thing before believing a feature works. Several bugs in this codebase
were invisible to a fully green suite.

## Conventions

- `declare(strict_types=1);` at the top of every PHP file, migrations included.
- Models declare fillability with the `#[Fillable([...])]` attribute, not
  `protected $fillable`. Casts go in `protected function casts(): array`.
- Mirror database defaults in `protected $attributes` — otherwise a fresh model
  reports `null` where the database would say `false`.
- Actions live in `app/Actions/<Area>/`, services in `app/Services/`.
- **Actions never authorise. Callers do**, through a Policy.

## Seams — do not bypass

Each of these exists so one concern has exactly one home. Going around one is
how a capability silently stops working.

| Seam | Rule |
|---|---|
| `Services\DocumentStorage` | The only code that resolves a disk. Tests may `Storage::fake`. |
| `Support\ProcessRunner` | The only code that shells out. Makes extraction testable with no binaries installed. |
| `Search\SearchIndex` | Keyword search. FTS5 on SQLite, LIKE elsewhere. |
| `Services\Search` | The only way anything searches — it resolves the viewer's reach so no caller can forget. |
| `Services\DirectoryAccess` | The only place per-directory access is decided. |
| `Providers\DoccumServiceProvider` | The single registration point: bindings, policies, observers, morph map. |

Storage, OCR, embeddings and the search index are all **provider seams**: an
embedded default plus opt-in remote alternatives. Follow that shape for
anything new.

## Authorisation

Two independent layers, and **both must pass**:

1. **Spatie permission** — what kind of action may this person ever perform.
2. **`directory_access`** — where, inherited down the subtree.

`manage` on a directory without the `files.upload` permission still cannot
upload. There is a test for that; keep it.

## Upgrade seam

doccum must stay on stock Laravel's upgrade path.

- Never edit `vendor/`. Never edit a framework migration — extend `users` with
  your own additive one.
- Publish only config files actually modified.
- Every macro, binding, policy and morph-map entry goes in
  `DoccumServiceProvider`, so an upgrade audit is one file.

## Testing

- **Never pass an interface to `toThrow()`.** Pest branches on `class_exists()`,
  which is false for interfaces, so `toThrow(Throwable::class)` silently becomes
  a substring match on the message and type-checks nothing.
- **No test may require an external binary.** `tesseract` is absent on some dev
  machines; everything goes through `ProcessRunner::fake()`.
- **Mutation-check every security test.** Delete the guard, confirm the test
  fails, restore it. A guard whose test passes without it is worse than none,
  because it looks protected.
- Scope assertions to their subject, not the whole table. `Model::count()`
  breaks the moment anything else legitimately writes a row.
- `RefreshDatabase` resolves `config('database.default')` lazily at **rollback**
  time, so a test that repoints the connection must reset it in `afterEach` or
  it cascades failures into later tests.
- The test queue is synchronous; production is not. A "before" assertion about
  queued work will not hold.

## Docker

- Base image `serversideup/php:8.4-frankenphp-bookworm` — no s6, so `supervisor`
  runs FrankenPHP, MinIO, both queue workers and the scheduler.
- MinIO's binary is copied from a pinned `quay.io/minio/minio` image; it is not
  downloadable any more.
- `/data` is the only persistent volume: SQLite, objects, `runtime.json`,
  `minio.env`. Nothing under `storage/` survives a rebuild.
- `bootstrap/cache/*.php` is dockerignored — built with dev dependencies, it
  breaks a `--no-dev` image.
- The app must boot with **no database**: `package:discover` runs during the
  image build. Guard anything that queries at boot.

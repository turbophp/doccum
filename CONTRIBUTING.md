# Contributing to doccum

doccum is self-hosted document management that ships as one container. Patches
are welcome. This file is the short version of what the codebase expects; the
long version is `CLAUDE.md`, which is written for both human and machine
contributors and is the authority where the two disagree.

## Before anything else: use Node 22

```bash
nvm use 22    # .nvmrc, and engines in package.json
```

This is not a style preference, and getting it wrong is the single most likely
way to waste a day here.

npm 10 (which ships with Node 22) records `react` — motion's peer dependency —
in `package-lock.json`. npm 11 (Node 24) removes it, and **rewrites the
lockfile as a silent side effect of `npm run build` and `npm ci`**, with no
output saying so. A lockfile generated on Node 24 fails CI with
`Missing: react@19.3.0 from lock file`, and fixing it on Node 24 undoes the fix
in the same breath — including the act of verifying the fix.

If you see that error:

```bash
nvm use 22 && rm -rf node_modules package-lock.json && npm install && npm ci
```

Then confirm `react` is in the lockfile before you commit.

## Getting set up

```bash
composer install
npm ci
cp .env.example .env && php artisan key:generate
php artisan migrate
npm run build
```

The full stack, which is what the product actually is:

```bash
docker compose up -d --build
```

Or the single container:

```bash
docker build -t doccum:local .
docker run -d -v doccum:/data -p 8080:8080 doccum:local
```

`compose.yaml` requires `MINIO_ROOT_PASSWORD` to be set before you bring it up.
There is a CI check that keeps it that way.

## What to run before you push

```bash
php artisan test                      # must be green
./vendor/bin/pint                     # format (--test to check without writing)
./vendor/bin/phpstan analyse          # must not add findings above the baseline
```

`phpstan-baseline.neon` enumerates findings that predate the current work. Add
none. If you genuinely need to regenerate it, that is what
`.github/workflows/phpstan-baseline.yml` is for, from a `baseline/**` branch —
not a hand edit.

**A green suite is necessary and not sufficient.** Several bugs in this
codebase were invisible to a fully green suite: a broken asset build, a Flux
component the image cannot resolve, a dropdown whose JavaScript never booted.
Run the container and drive the thing before believing a feature works.

## Conventions

- `declare(strict_types=1);` at the top of **every** PHP file, migrations
  included.
- Models declare fillability with the `#[Fillable([...])]` attribute, not
  `protected $fillable`. Casts go in `protected function casts(): array`.
- Mirror database defaults in `protected $attributes` — otherwise a fresh model
  reports `null` where the database would say `false`.
- Actions live in `app/Actions/<Area>/`, services in `app/Services/`.
- **Actions never authorise. Callers do**, through a Policy.

## Seams — do not go around these

Each exists so one concern has exactly one home. Going around one is how a
capability silently stops working.

| Seam | Rule |
|---|---|
| `Services\DocumentStorage` | The only code that resolves a disk. Tests may `Storage::fake`. |
| `Support\ProcessRunner` | The only code that shells out. Makes extraction testable with no binaries installed. |
| `Search\SearchIndex` | Keyword search. FTS5 on SQLite, LIKE elsewhere. |
| `Services\Search` | The only way anything searches — it resolves the viewer's reach so no caller can forget. |
| `Services\DirectoryAccess` | The only place per-directory access is decided. |
| `Providers\DoccumServiceProvider` | The single registration point: bindings, policies, observers, morph map. |

Storage, OCR, embeddings and the search index are **provider seams**: an
embedded default plus opt-in remote alternatives. Follow that shape for
anything new.

## Authorisation has two layers and both must pass

1. **Spatie permission** — what kind of action may this person ever perform.
2. **`directory_access`** — where, inherited down the subtree.

`manage` on a directory without the `files.upload` permission still cannot
upload. `tests/Feature/FilePolicyTest.php` has a test for that; do not
weaken it.

## Staying on Laravel's upgrade path

- Never edit `vendor/`. Never edit a framework migration — extend `users` with
  your own additive one.
- Publish only config files you actually modified.
- Every macro, binding, policy and morph-map entry goes in
  `DoccumServiceProvider`, so an upgrade audit is one file.

## Testing

Pest, under `tests/`. A few rules that are not guessable:

- **Never pass an interface to `toThrow()`.** Pest branches on `class_exists()`,
  which is false for interfaces, so `toThrow(Throwable::class)` silently
  becomes a substring match on the message and type-checks nothing.
- **No test may require an external binary.** `tesseract` is absent on some dev
  machines; everything goes through `ProcessRunner::fake()`.
- **Mutation-check every security test.** Delete the guard, confirm the test
  fails, restore it. A guard whose test passes without it is worse than none,
  because it looks protected. Registered guards live in `.github/mutations.json`
  and CI re-runs them both directions on every push — if you add one, add its
  entry.
- **Scope assertions to their subject.** `Model::count()` breaks the moment
  anything else legitimately writes a row.
- `RefreshDatabase` resolves `config('database.default')` lazily at **rollback**
  time, so a test that repoints the connection must reset it in `afterEach` or
  it cascades failures into later tests.
- The test queue is synchronous; production is not. A "before" assertion about
  queued work will not hold.
- **A UI item is not done until the container smoke drives it.**
  `.github/scripts/container-smoke.mjs` is the only place a Livewire surface is
  exercised the way a browser meets it, and it is the only place that can see a
  broken asset build.
- **Never skip, disable or quarantine a test** to get a build green.

## What CI will run

Pull requests get, from `tests.yml`: `lint` (pint check plus phpstan against
the baseline), `assets`, `test` across a six-way matrix (PHP 8.4 and 8.5 ×
SQLite, PostgreSQL, MySQL), `guards` (the registered mutations),
`compose-secret`, `docs-name-the-real-image`, `changelog-section`, and `image`
(builds the container and runs the smoke). Plus `ledger` (`validate`,
`invocation`), `pages` (`build`, `deploy`) and `security` (`composer-audit`,
`dependency-review`).

CI is the verification of record, not a formality: it covers three databases
and it boots the container, and your laptop does neither.

## Pull requests

- One change per pull request. A change that grows past what its title
  describes wants splitting, not widening.
- Say what you verified and how. "Tests pass" is weaker than naming the test.
- If you cite evidence — a mutation run, a benchmark, a manual check — make
  sure it happened. A citation is a claim and is read as one; a fabricated one
  is worse than none, because the next reader sees it vouched for and stops
  looking.

## Reporting a vulnerability

Not here. See `SECURITY.md`.

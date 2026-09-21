# Working on doccum

Self-hosted document management: directories, versioned files, governed
properties, OCR'd text, permission-filtered search. Ships as one container.

## Commands

```bash
php artisan test                      # full suite; must be green before any commit
php artisan test --filter=SomeTest    # focused
./vendor/bin/pint                     # format (--test to check)
./vendor/bin/phpstan analyse          # 37 findings in phpstan-baseline.neon; add none
docker compose up -d --build          # the real stack
docker run -d -v doccum:/data -p 8080:8080 doccum:local   # single container
```

The container is the product. A green suite is necessary and not sufficient —
run the thing before believing a feature works. Several bugs in this codebase
were invisible to a fully green suite.

## Node and the lockfile

Use Node 22 (`.nvmrc`, and `engines` in package.json). CI pins it, and npm
versions disagree about this lockfile in a way that breaks the build:

- npm 10 (Node 22) records `react` — motion's peer — in `package-lock.json`.
- npm 11 (Node 24) removes it, and **rewrites the lockfile as a side effect of
  `npm run build` and `npm ci`**, silently, with no output.

So a lockfile generated on Node 24 fails CI with `Missing: react@19.3.0 from
lock file`, and "fixing" it on Node 24 undoes the fix in the same breath —
including the act of verifying it. This has cost four CI cycles. If that error
appears, regenerate under Node 22 and check `react` is in the lockfile before
committing:

```bash
nvm use 22 && rm -rf node_modules package-lock.json && npm install && npm ci
```

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

## Boot-time code

**Ensure means create what is absent, never assert what a set must be.**
Code that runs unattended on every boot against a database a human can edit
may create what is missing and may not replace what is there. The test is not
the function's name but the diff it would produce against a database somebody
has touched: `syncPermissions()` produces a deletion, `findOrCreate` plus a
`wasRecentlyCreated` guard produces nothing (`decision/0073`).

## Upgrade seam

doccum must stay on stock Laravel's upgrade path.

- Never edit `vendor/`. Never edit a framework migration — extend `users` with
  your own additive one.
- Publish only config files actually modified.
- Every macro, binding, policy and morph-map entry goes in
  `DoccumServiceProvider`, so an upgrade audit is one file.

## Testing

- **A citation of evidence is a claim, and is checked like one.** Writing
  "confirmed by mutation" for a mutation nobody ran is worse than writing
  nothing: it is self-sealing, because the next reader sees the assertion
  vouched for and stops looking (`decision/0080`).
- **Counting readers is not counting sources.** Two readers of one upstream
  value are one source wearing two hats; a comparison between them agrees
  with itself at a false value. Trace each back to where the value was
  originated, not to where it was read (`decision/0080`).

- **Never pass an interface to `toThrow()`.** Pest branches on `class_exists()`,
  which is false for interfaces, so `toThrow(Throwable::class)` silently becomes
  a substring match on the message and type-checks nothing.
- **No test may require an external binary.** `tesseract` is absent on some dev
  machines; everything goes through `ProcessRunner::fake()`.
- **Mutation-check every security test.** Delete the guard, confirm the test
  fails, restore it. A guard whose test passes without it is worse than none,
  because it looks protected.
- **A witness can stop witnessing, and the change that does it is somewhere
  else.** A registered entry's `expectFailing` test proves a guard only while
  that test still *reaches* it. Move a refusal earlier in the same method --
  scope a lookup so it 404s before an `authorize()` that used to do the
  refusing -- and deleting the guard stops altering the test's outcome, so the
  entry silently measures nothing. Nothing weakened; the proof did. This is not
  the bullet above: there the test never exercised the guard, here it did and
  then stopped. So when `guards` fails on an entry your diff did not touch,
  the answer is almost never to weaken the entry. Find the test that still
  reaches the guard, repoint `expectFailing` at it, and say in the entry's
  `why` which test it used to name and what moved -- "the guard is not
  load-bearing" is a claim about a specific test, and which one it was is what
  the next reader needs (`decision/0111`).
- Scope assertions to their subject, not the whole table. `Model::count()`
  breaks the moment anything else legitimately writes a row.
- `RefreshDatabase` resolves `config('database.default')` lazily at **rollback**
  time, so a test that repoints the connection must reset it in `afterEach` or
  it cascades failures into later tests.
- The test queue is synchronous; production is not. A "before" assertion about
  queued work will not hold.
- **A UI item is not done until the container smoke drives it.**
  `.github/scripts/container-smoke.mjs` is the only place a Livewire surface is
  exercised the way a browser meets it. A Blade assertion renders the view with
  the test renderer, so it cannot see a broken asset build, a component Flux
  fails to resolve in the image, or a dropdown whose JS never booted -- every
  one of which leaves the suite green and the feature unusable.
- **A smoke assertion is worth only what a mutation says it is.** Each v1 UI
  item extends the smoke with at least one assertion that fails when the
  feature is broken, and "at least one" means one that has been *shown* to
  fail. Break the thing deliberately, run the smoke against that image, and
  record the run. Reasoning about which half of a check carries the weight is
  unreliable: the topbar check asserted the account menu hidden before the
  click and visible after, and the hidden-before half was argued to be the
  load-bearing one. Mutation proved the reverse -- with no JavaScript in the
  image at all the menu was still hidden, so only the visible-after-click half
  proves anything. Prefer an assertion that requires the feature to *do*
  something over one that observes a resting state, and never cite an
  unmutated assertion as evidence.

- **A guard that reports by printing is defeated by a pipe.** If a checking
  tool signals its own scope in prose -- "Compared 18 commits", "skipping the
  X check" -- that defence needs three things to hold: the line is emitted,
  the reader sees it, and the reader notices when it is *missing*. The third
  is the weak one. A wrong value is a signal; an absent line is a non-event,
  indistinguishable from not having looked. And the second fails routinely
  for reasons unrelated to care: these runs get invoked as `| tail -4`,
  because the verdict is at the end, and the scope line is at the top. So
  when a tool can be invoked in a way that checks nothing, make it REFUSE,
  not announce. `validate-ledger.php` takes `<main-sha>` positionally, so
  `MAIN_SHA=<sha> php .github/scripts/validate-ledger.php` set a variable it
  never read, skipped the whole main-push-history check, and printed the same
  "Ledger is sound"; its own comment had anticipated exactly that and chosen
  a printed count as the defence. It cost two incidents -- a tree CI refused
  being called sound, and a commit message citing a "Compared 19 commits"
  line that was never emitted. This is not `decision/0080`: that rule is
  about the reader's discipline in checking a citation, this is about the
  tool's, and a guard delegated to a reader is not a guard (`decision/0113`).

## Docker

- Base image `serversideup/php:8.5-frankenphp-bookworm` — no s6, so `supervisor`
  runs FrankenPHP, MinIO, both queue workers and the scheduler.
- MinIO's binary is copied from a pinned `quay.io/minio/minio` image; it is not
  downloadable any more.
- `/data` is the only persistent volume: SQLite, objects, `runtime.json`,
  `minio.env`. Nothing under `storage/` survives a rebuild.
- `bootstrap/cache/*.php` is dockerignored — built with dev dependencies, it
  breaks a `--no-dev` image.
- The app must boot with **no database**: `package:discover` runs during the
  image build. Guard anything that queries at boot.

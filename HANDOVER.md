# doccum — handover

**Status:** installable and usable, and considerably further along than the
counts that used to sit here claimed -- they said 99 commits and 386 tests when
the tree held 795 and 898. Deliberately not restated: a number copied out of the
repository into prose is a number that rots. `git rev-list --count main` and the
`tests` workflow are the sources. Published at https://github.com/turbophp/doccum.

```bash
docker build -t doccum:local .
docker run -d --name doccum -v doccum:/data -p 8080:8080 doccum:local
# open http://localhost:8080 and complete the setup screen
```

That is the whole installation: one container running the web app, both queue
workers, the scheduler and object storage; one volume; no configuration file,
no database to create, no bucket to provision, no default password.

---

## What works

| Area | State |
|---|---|
| Install | Browser installer; detects an existing database and attaches instead of overwriting |
| Directories | Tree with materialised paths; move rewrites the subtree and refuses cycles |
| Files | Versioned; re-upload adds a version; trash and restore |
| Storage | Embedded MinIO in-image, or S3 / R2 / Spaces / Wasabi / B2 / Azure / custom |
| Access | Spatie capabilities **and** per-directory grants inherited down subtrees |
| Properties | Admin-defined, typed, validated; values in typed columns |
| Extraction | PDF text layer, OCR fallback, Office XML, plain text — on the ingest queue |
| Search | FTS5 with BM25, permission-filtered inside the query |
| Periods | Closing rolls up counts and bytes; archived periods reject writes |
| Purging | Irreversible, guarded three ways, dry-run by default, off on a schedule unless enabled |

## What is not built

| Remaining | Plan |
|---|---|
| Semantic search | spec §8a — designed, additive |
| Vision-model OCR | spec §7a — designed, optional dependency |

**Four rows left this table because they shipped**, and they are named rather
than silently dropped, because this section said they were unbuilt for long
enough to mislead:

- **Three-pane shell** — `app/Livewire/Files/Browser.php`; it is the view in the
  README screenshot.
- **Admin surface** — five screens under `app/Livewire/Admin/`, all five §10
  Settings sections routed.
- **REST API with Sanctum** — `app/Http/Controllers/Api/V1/`, 16 paths described
  by `docs/api/openapi.json` and held to the live route set in both directions by
  `tests/Feature/Api/OpenApiContractTest.php`.
- **Documentation site** — `docs/self-hosting/` (nine pages) and
  `.github/workflows/pages.yml`; the build job is green and `deploy` skips until
  two repository settings are made (issue #27).

Everything above is specified in `docs/superpowers/specs/2026-09-15-doccum-design.md`.
Plans live in `docs/superpowers/plans/`. Each is task-by-task with literal code
and is meant to be executed by an agent; read `CLAUDE.md` first.

**Purging is built.** All four tasks of the archive plan are done.
`PeriodPurger::plan()` answers "what would this destroy" without writing;
`purge()` refuses unless the period is archived, has aged past the
`retention.purge_after_years` setting, and holds nothing under legal hold — and
a refusal names every blocker, not just the first. `doccum:purge-period` is a
dry run without `--force`; `doccum:purge-expired` reports and deletes nothing
unless the `retention.auto_purge` setting is switched on. Both are read through
`Settings::get()` and are editable from Settings → Instance settings; they moved
under `config('doccum.settings')` in item/admin-instance-settings, so reading
them with `config('doccum.retention.*')` now silently returns null.

Development now runs as an hourly autonomous loop to a tagged `v1.0.0`. The
protocol is `docs/LOOP.md`; the state of the road is `docs/ledger/ledger.jsonld`.

---

## The shape of the code

Everything optional is a **provider seam**: an embedded default that works with
no configuration, plus opt-in alternatives behind one interface.

| Seam | Embedded default | Alternatives |
|---|---|---|
| Storage | MinIO inside the image | S3, R2, Spaces, Wasabi, B2, Azure, custom |
| Database | SQLite | PostgreSQL, MySQL, MariaDB |
| Search index | FTS5 (BM25) | LIKE fallback; tsvector and hosted engines later |
| OCR | Tesseract | vision model on bundled llama.cpp; Azure DI, Textract, Document AI |
| Embeddings | not built | llama.cpp bundled, Ollama, OpenAI, Voyage, Cohere |

The decisive argument for embedding MinIO rather than a local filesystem disk:
because embedded storage **is** S3, there is one storage code path. A local-disk
default would have made the configuration almost everyone runs the one the code
least exercises.

### Configuration lives in two places, deliberately

- **`/data/runtime.json`** (encrypted, `0600`) holds *only* the database
  connection — the single genuine chicken-and-egg.
- **The `settings` table** holds everything else, secrets encrypted at rest.

So pointing a new container at an existing doccum database restores the whole
instance. `APP_KEY` must travel with it: settings secrets and 2FA secrets are
sealed with it, and an `instance.key_check` canary refuses a mismatch rather
than half-booting.

---

## Things that will bite you

- **A green suite is not proof.** Search shipped completely inert while 370
  tests passed, because every search test primed the index by hand and nothing
  exercised the upload path. `EndToEndSearchTest` exists to stop that
  recurring — it touches the index nowhere.
- **`toThrow(Throwable::class)` type-checks nothing.** Pest branches on
  `class_exists()`, false for interfaces, so it degrades to a message substring
  match. Always name a concrete class.
- **Mutation-check security tests.** Three guards in this codebase passed
  without the code they were guarding.
- **The app must boot with no database.** `package:discover` runs during the
  image build; a query at boot fails every CI build and nothing local reveals it
  because layer caching hides it.
- **`Schema::hasTable()` throws without a database**, it does not return false.
- **Mass deletes fire no model events**, so `DirectoryAccess`'s memo is not
  invalidated by `Model::query()->delete()`. Revoke through the model.
- **MinIO no longer publishes binaries.** `dl.min.io` returns 410 and the GitHub
  release has no assets; the binary is copied from a pinned container image.
- **`wire:model` is deferred.** Anything driving conditional markup needs
  `wire:model.live`, or the form silently never re-renders.

---

## Before a public release

1. ~~**Replace `doccum-secret` in `compose.yaml`.**~~ Done (item/compose-secret):
   `compose.yaml` now requires `MINIO_ROOT_PASSWORD` (compose's `${VAR:?message}`
   form, no default) for the `storage` profile's MinIO, `minio-init`, and the
   `AWS_SECRET_ACCESS_KEY` the app/worker/scheduler containers would otherwise
   fall back to. It still does not touch the embedded stack's own random
   per-install password (`docker/entrypoint.d/48-doccum-storage.sh`); an
   operator must now set `MINIO_ROOT_PASSWORD` before ANY `docker compose`
   command, not only one selecting the `storage` profile: compose interpolates
   the whole file regardless of profile (verified on Compose v5.1.1 — even
   `--profile nonexistent` fails), so there is no way to scope a `${VAR:?}` to
   one profile. That cost was accepted deliberately in exchange for a refusal
   CI can prove without booting anything; see decision/0079.
2. **Tag the first release.** `.github/workflows/release.yml` builds multi-arch
   (amd64/arm64) and pushes to `ghcr.io/turbophp/doccum`. The README and the
   self-hosting docs already name that ref; the `OWNER` placeholder is gone
   and a CI check keeps it gone.

   **Which tag is an open question — see issue #302.** This step used to say
   `v0.1.0` flatly. Under the plan `decision/0083` adopted, the first tag is a
   throwaway PRE-RELEASE (`v0.1.0-rc1`), and the suffix is mandatory rather
   than cosmetic: under `docker/metadata-action`'s default `latest=auto`, a
   bare `v0.1.0` would move the `latest` tag, which a rehearsal must not do.
   Under the alternative in #302 the first tag is a real release and `v0.1.0`
   is correct. So the step names neither until that is decided.

   **A PRE-RELEASE IMAGE OUTLIVES ITS TAG, AND REMOVING IT IS MANUAL.**
   Deleting a tag and its GitHub Release does **not** delete the container
   image it published: `ghcr.io` package versions are a separate resource, and
   `GITHUB_TOKEN` has no permission to delete one, so no workflow can clean up
   after itself. Whatever the rehearsal publishes stays published until a
   person removes it.

   doccum's position: **the rc image stays.** A version tagged `0.1.0-rc1`
   sitting in the package list is honest — it was built and it did run — and a
   bare `docker pull ghcr.io/turbophp/doccum` cannot reach it, because
   `release.yml` publishes `latest` only for a tag with no `-` in it. So it
   misleads nobody following the README. If you want it gone anyway, it is
   **your** step and there is no automation to wait for: GitHub → the
   repository's Packages → `doccum` → the version → Delete. Do it after the
   real release, not before, so the rehearsal stays inspectable while it is
   still the only thing that has run the pipeline.
3. **Make the ghcr package public. It will not be.** GitHub publishes a new
   package **private**, and a public repository does not change that: its
   documentation says a package linked to a repository "automatically inherits
   the access permissions (but not the visibility) of the linked repository".
   So the moment step 2's tag publishes, `docker run ghcr.io/turbophp/doccum` —
   the command `README.md` calls the whole installation — answers `denied` for
   everyone who is not you.

   **This step cannot be done before step 2.** The package does not exist until
   something publishes it, which is why this is not grouped with the repository
   settings a person can set in advance. GitHub → Packages → `doccum` →
   Package settings → Change visibility → **Public**.

   **CI will tell you, rather than leaving you to remember.** `release.yml`'s
   `verify-anonymous-pull` job pulls the published image with no credentials at
   all — every other job that pulls it logs in first, correctly, which is
   exactly why none of them would notice — and its failure names this step.
   Expect it red on the rehearsal tag: that is the rehearsal doing its job.
   `item/tag-v1-0-0` carries a clause requiring it green, so the loop cannot
   report v1 finished over a README nobody can follow.
4. Consider image size. The numbers live in `README.md`'s "Image size" table and
   are ratcheted in CI against `.github/image-budget.json`, so they are not
   restated here -- the figure this step used to carry (~1.34 GB) had drifted far
   from the measured one. Alpine and fewer OCR languages remain the levers, both
   trade-offs.

**A step was removed rather than corrected, and image size took its number:
"Finish purging, or document that retention is manual."** Purging was already
finished when that line was written --
this same file says so two sections up, in full detail, under **Purging is
built.** A checklist item that the document itself refutes is worse than none,
because a reader who trusts the checklist stops to do work that does not exist.

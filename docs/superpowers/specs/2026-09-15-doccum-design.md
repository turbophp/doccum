# doccum — Design

Date: 2026-09-15
Status: Approved (pending final spec review)

**Revisions — 2026-09-18 (`item/spec-reconcile`, see `decision/0077`):**
reconciled this document against the shipped product without renaming or
removing any heading. §2's "Search engine" row is marked superseded — Laravel
Scout was never installed; search runs through the project's own `SearchIndex`
seam described in §8. The other Scout mentions in §13–§15 (default driver,
env vars, test doubles, the scaffold list) are corrected to describe that same
seam rather than Scout, for the same reason. §13's base image is corrected
from 8.4 to 8.5 to match the Dockerfile. §16's FTS5 entry is marked superseded
— `Fts5SearchIndex` has shipped as the SQLite default described in §8, not a
post-v1 optimisation. This is a content-only reconciliation: no claim is made
that the surrounding prose is otherwise complete or current, only that these
specific, verified points of drift are corrected or marked.

**Revisions — 2026-09-18 (`item/spec-section8-reconcile`, see `decision/0077`,
`decision/0084`):** §8 itself still described a system that was never built,
even after the pass above — verified against the code, not inferred. The
projection table's columns are corrected from `searchable_type`/`searchable_id`
to the real `subject_type`/`subject_id` (`database/migrations/..._create_search_documents_table.php`,
`app/Models/SearchDocument.php` — no migration anywhere names a `searchable_*`
column). The Scout mechanics in "The projection table" and "Maintenance"
(`toSearchableArray()`, `qualifyColumn()`, `#[SearchUsingFullText]`, "the Scout
entry") are replaced with what `App\Services\SearchIndexer` and
`App\Models\SearchDocument` actually do — there is no Scout `Searchable` trait
anywhere in `app/`. "Permission filtering" is rewritten: filtering is not a
Scout `query()` callback intersecting `ancestor_ids`; `Services\Search` resolves
`DirectoryAccess::viewableDirectoryIds()` (already subtree-expanded) once per
query and hands it to `SearchIndex::search()`, and each implementation enforces
it itself, inside its own query, as a predicate against the projection row's own
`directory_id` column — confirmed by reading `app/Services/Search.php`,
`app/Search/SearchIndex.php`, `app/Search/Fts5SearchIndex.php` and
`app/Search/LikeSearchIndex.php` directly. The seam table's `TsvectorSearchIndex`
row is marked unbuilt, pointing at `item/tsvector-index` (post-v1) — `app/Search/`
contains only `Fts5SearchIndex`, `LikeSearchIndex`, `PropertyFilter`, `SearchHit`,
`SearchIndex`, and `Terms`. As before: this is a content-only reconciliation,
headings unchanged, and no claim is made that the rest of §8's prose is
otherwise current — only that these specific, verified points are corrected or
marked. There is no test for prose; `validate-ledger.php` proves only that no
spec-anchor heading moved.

## 1. Overview

doccum is a self-hosted, open-source document management system. Users organise
documents in a directory tree, attach governed metadata to them, and search
across names, metadata, and the text inside the documents themselves — including
OCR of scanned material. Access is controlled per directory subtree. Storage is
partitioned by period so whole years can be archived and purged as a unit.

The product ships as a single `docker compose up` with no required
configuration, and every infrastructure component can be detached and pointed at
a remote service through environment variables alone.

### Goals

- Directory tree with files, versioned, soft-deletable.
- Governed, typed metadata attachable to both directories and files.
- Search across directory names, file names, metadata values, and extracted
  document text (including OCR), permission-filtered.
- Per-directory access control inherited down the subtree, layered on global
  role-based capabilities.
- Period-partitioned storage enabling bulk archive and purge.
- Per-user home directories namespaced by username, and public signup as an
  operator-toggleable setting.
- Token-authenticated REST API for content operations, integrator-facing.
- Generated API reference plus a self-hosting guide.
- Zero-config self-hosting; any component replaceable with a remote equivalent.
- Stays on stock Laravel's upgrade path; no framework forks or vendor patches.

### Non-goals (v1)

- Multi-tenancy. Single organisation per deployment.
- Antivirus scanning of uploads.
- Full-text search relevance tuning against a specific engine (deferred).
- Real-time collaborative editing, check-in/check-out locking, workflow/approval
  chains, e-signatures.
- Admin operations over the API (user/role management, period purge).
- Webhooks and outbound event delivery.

## 2. Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Search scope | Metadata + extracted text + OCR | Documents must be findable by content, not just filename. |
| Search engine | ~~Laravel Scout, engine deferred~~ **Superseded, see §8** — the project's own `SearchIndex` seam, engine deferred | Original rationale: code against Scout's interface; ship on the database driver, adopt Typesense/Meilisearch once there is real data to tune against. Superseded because Scout was never installed — the database-driver `LIKE` scan Scout would have shipped on is exactly what §8 identifies as the worst default, so the seam ships FTS5 on SQLite directly instead and keeps the later engine swap. |
| Properties | Admin-defined definitions, typed values | Validation, consistent filters, working range/date sorting. |
| Access control | Spatie roles + per-directory ACL with subtree inheritance | Capability and location are separate questions. |
| File lifecycle | Versions + soft-delete trash | Standard DMS expectation; overwrites recoverable. |
| UI | Laravel 13 + Livewire 4 starter kit + Flux UI (free) | Auth scaffolding and component library out of the box. |
| Rollups | Period-partitioned storage + bulk purge | Purging a period is a prefix operation, not a per-file hunt. |
| Period source | File creation (upload) date | Immutable; object keys never relocate. |
| Tenancy | Single org | No tenant key on every table. |
| Signup | Operator setting, default off; username required | Safe by default for a self-hosted install. |
| Home directories | One per user, named for the username, owner holds `manage` | Personal space with no second permission concept. |
| Default access | Home directory only | A new account can reach nothing shared until granted. |
| Property search | Own index entries + flattened into parents | Both "find the invoice" and "where is this property used". |
| Container runtime | FrankenPHP via `serversideup/php` | No nginx sidecar; boot automation already in the image. |
| First-run admin | First-run setup screen | No default credentials ever exist on disk. |
| Shell | Full-width Dropbox-style, topbar nav | No sidebar chrome above the fold; lowercase wordmark + version pill left, Home/Files/Settings/My Account right. |
| API auth | Sanctum tokens, abilities ∩ permissions ∩ ACL | A token can never exceed its owner. |
| API surface | Content operations only | Admin and purge stay UI-only; smallest dangerous surface. |
| API transfer | Presigned direct to MinIO | Large files never touch PHP; no upload ceiling. |
| Docs | Generated OpenAPI + self-hosting guide | Reference cannot drift from code; operators get a runbook. |
| Upgrade seam | Stock Laravel, no vendor edits, one registration point | A major bump is a constraint change, not a fork reconciliation. |
| Settings | `config/doccum.php` defaults, `settings` table overrides | Empty table still boots; operator changes survive deploys. |
| Licence | MIT | Maximally permissive adoption. |

## 3. Architecture

```
users ──┬─ spatie: roles, permissions, model_has_roles, role_has_permissions
        └─ directory_access (grantee morph: User | Role)

directories ── self-referencing tree (parent_id + materialised path)
    └── files ── file_versions ── file_texts
property_definitions ── properties (morph: Directory | File)
archive_periods
search_documents (projection: Directory | File | Property)
```

Layers:

- **Models / Eloquent** — persistence and relationships only.
- **Actions** (`app/Actions`) — single-purpose write operations (`StoreFileVersion`,
  `MoveDirectory`, `PurgePeriod`). Invoked by Livewire components, commands, jobs.
- **Services** (`app/Services`) — `DirectoryAccess`, `SearchIndexer`, extraction
  strategies. Stateless, unit-testable.
- **Policies** — the only place authorisation is decided; they delegate to
  `DirectoryAccess`.
- **Jobs** — `ExtractText`, `ReindexSearchDocument`, period maintenance.
- **Livewire components** — presentation and user interaction; no business rules.
- **API controllers / Resources** — thin HTTP translation over the same Actions
  and Policies the UI uses. No logic lives only in the API.

### Upgrade seam

doccum must stay on the upgrade path of a stock Laravel application. A major
version bump should be reading the upgrade guide and bumping a constraint — never
reconciling a fork.

- **`vendor/` is never edited.** No patches, no forks, no `composer` replace of a
  framework package.
- **Framework classes are consumed, not subclassed**, except at extension points
  Laravel documents: service providers, custom casts, validation rules, custom
  Blade/Livewire components, middleware, and policies.
- **One registration point.** Every macro, binding, morph map, Blade directive,
  and policy registration lives in `DoccumServiceProvider`, so auditing what we
  changed during an upgrade is reading one file.
- **Publish only the config files we actually modify.** Everything else stays on
  framework defaults so new options arrive for free. Product settings live in
  `config/doccum.php`, not scattered through published framework configs.
- **Framework tables are only ever added to.** `users` gains columns through our
  own additive migration; no framework migration is edited.
- **Starter-kit output is our code** once scaffolded, but stays conventional — we
  do not restructure auth scaffolding, so Laravel's upgrade guides still apply
  to it verbatim.
- **Domain logic lives in `app/Actions`, `app/Services`, and `app/Support`**,
  depending on framework contracts rather than concrete internals, so the
  business rules are the part least exposed to a framework change.
- Constraints stay on caret ranges; CI runs the suite against the latest patch.

## 4. Data model

### `users`

Framework table, extended **additively** per the upgrade seam (§3) — the
framework's own migration is never edited.

| Column | Type | Notes |
|---|---|---|
| username | string(64) unique | required; `[a-z0-9._-]`, lowercase |

A username is required at signup and at admin creation, and it **namespaces the
user's home directory**, whose `name` is the username.

Because the materialised path is built from ids rather than names, changing a
username renames one directory row and nothing else — no descendant path rewrite,
and no effect on object keys, which are built from period and file uuid (§6).

### `directories`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| parent_id | bigint null fk→directories | null = root |
| name | string(255) | |
| path | string(512) indexed | materialised ancestor ids, e.g. `/1/5/9/`; 512 keeps the index within MySQL's key limit |
| depth | unsigned tinyint | denormalised from path |
| home_user_id | bigint null unique fk→users | set = this is that user's home directory |
| created_by | bigint fk→users | |
| timestamps, deleted_at | | soft deletes |

Unique `(parent_id, name)` among non-trashed rows, compared case-insensitively
and accent-sensitively, after NFC normalisation, through a persisted
`name_key` column (`App\Support\NameKey`; issue #46's decision comment) —
`Report.pdf` and `report.pdf` are the same name, `résumé.pdf` and
`resume.pdf` are not.

A **home directory** is an ordinary root-level directory with `home_user_id`
set and `name` equal to the owner's username — no separate table and no second
permission concept. It is created whenever a user is created, however they
arrive (first-run, admin, or signup), and the owner receives a `manage` grant on
it. Deleting a user leaves the home directory standing; reassigning it is an
admin action.

`path` is a materialised path rather than a recursive CTE because the two hot
queries — "everything beneath X" (`path LIKE '/1/5/%'`) and "every ancestor of X"
(parse the path) — must behave identically on SQLite, MySQL, and Postgres.
Moving a directory rewrites descendant paths with a single `UPDATE ... REPLACE()`.

### `files`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| uuid | uuid unique | used in object keys |
| directory_id | bigint fk→directories | |
| name | string(255) | |
| current_version_id | bigint null fk→file_versions | |
| mime | string(191) | of current version |
| size | unsigned bigint | of current version |
| checksum | string(64) | sha256 of current version |
| period_year | smallint indexed | from created_at |
| period_month | tinyint indexed | from created_at |
| legal_hold | boolean default false | exempts from purge |
| created_by | bigint fk→users | |
| timestamps, deleted_at | | soft deletes |

Unique `(directory_id, name)` among non-trashed rows, compared
case-insensitively and accent-sensitively, after NFC normalisation, through a
persisted `name_key` column (`App\Support\NameKey`; issue #46's decision
comment) — `Report.pdf` and `report.pdf` are the same name, `résumé.pdf` and
`resume.pdf` are not.
`period_year`/`period_month` are denormalised so period queries and reporting
never scan object keys.

### `file_versions`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| file_id | bigint fk→files | |
| version_number | unsigned int | sequential per file |
| object_key | string(1024) | see §6 |
| size | unsigned bigint | |
| mime | string(191) | |
| checksum | string(64) | sha256 |
| uploaded_by | bigint fk→users | |
| created_at | timestamp | |

Immutable: rows are inserted and deleted, never updated.
Unique `(file_id, version_number)`.

### `file_texts`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| file_version_id | bigint fk→file_versions cascade | unique |
| status | enum(pending, processing, done, failed, unsupported) | |
| extractor | string(32) null | which strategy ran |
| text | longtext null | extracted content |
| chars | unsigned int default 0 | |
| error | text null | |
| timestamps | | |

Kept off `files` so directory listings never drag megabytes of OCR text.

### `property_definitions`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| key | string(64) unique | slug, used as the index field name |
| label | string(191) | |
| data_type | enum(string, text, number, date, boolean, select) | |
| options | json null | choices when `select` |
| is_required | boolean default false | |
| applies_to | enum(directory, file, both) | |
| sort_order | unsigned int default 0 | |
| timestamps | | |

### `properties`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| property_definition_id | bigint fk | |
| subject_type | string | Directory or File |
| subject_id | bigint | |
| value_string | string(1024) null | string, text, select |
| value_number | decimal(20,6) null | number |
| value_date | date null | date |
| value_boolean | boolean null | boolean |
| timestamps | | |

Exactly one value column is populated, chosen by the definition's `data_type`.
Typed columns rather than a single stringly-typed `value` so numeric ranges and
date ordering work in SQL.

Index `(subject_type, subject_id)`, and
`(property_definition_id, value_string)` for lookup by value.
Unique `(property_definition_id, subject_type, subject_id)`.

### `directory_access`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| directory_id | bigint fk→directories cascade | |
| grantee_type | string | User or Role |
| grantee_id | bigint | |
| level | enum(view, edit, manage) | |
| timestamps | | |

Unique `(directory_id, grantee_type, grantee_id)`.
Morph grantee so a grant to a person and a grant to a whole role share one table.

### `settings`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| key | string(128) unique | dotted, e.g. `instance.name` |
| value | json null | typed payload |
| updated_by | bigint null fk→users | |
| timestamps | | |

Operator-editable instance configuration. Keys include `instance.name` (set on
the first-run screen), `auth.public_signup` (default `false`),
`auth.default_role`, `directories.auto_home` (default `true`), retention
overrides, and upload limits.

The relationship with `config/doccum.php` is deliberate and one-directional:
**`config/doccum.php` ships the defaults in code; `settings` rows override them
at runtime.** A `Settings` service reads through a cache, falls back to config
when no row exists, and casts on the way out. Nothing reads the table directly.
This keeps a fresh install fully functional with an empty `settings` table —
required by the zero-configuration boot in §13 — and keeps operator changes out
of files a deployment would overwrite.

### `archive_periods`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| year | smallint | |
| month | tinyint null | null = the year as a whole |
| archived_at | timestamp null | |
| purged_at | timestamp null | |
| file_count | unsigned int default 0 | at close time |
| byte_count | unsigned bigint default 0 | at close time |
| notes | text null | |

Unique `(year, month)`.

### `search_documents`

See §8. Projection table backing all search.

## 5. Access control

Two independent layers; **both** must pass.

**Layer 1 — Spatie roles/permissions.** What kind of action may this person ever
perform? Permissions include `files.upload`, `files.delete`, `files.restore`,
`directories.create`, `directories.manage`, `properties.manage`,
`users.manage`, `periods.manage`, and `directories.view-all` (admin bypass of
layer 2).

**Layer 2 — `directory_access`.** Where may they perform it? Levels are ordered
`view` < `edit` < `manage`:

- **view** — list the directory, see its files, download them.
- **edit** — upload, rename, set properties, soft-delete files.
- **manage** — grant/revoke access, move or delete the directory itself.

A grant on a directory applies to its **entire subtree**. Files inherit their
directory's level. The effective level for a user on a directory is the
**highest** grant found on that directory or any ancestor, considering both
grants made directly to the user and grants made to any role they hold.

**Grant-only; there are no deny rules.** Deny semantics in an inherited tree
produce surprising results and expensive resolution. A narrower grant lower in
the tree covers the legitimate cases.

`App\Services\DirectoryAccess` is the single source of truth:

- `levelFor(User $u, Directory $d): ?AccessLevel`
- `can(User $u, Directory $d, AccessLevel $required): bool`
- `viewableDirectoryIds(User $u): array` — granted directory ids expanded to
  their subtrees via `path LIKE`, used for listing and search filtering.

Results are memoised per request. `DirectoryPolicy` and `FilePolicy` call this
service and contain no rules of their own.

Every user holds `manage` on their own home directory through an ordinary
`directory_access` row written at user creation. Home directories are not a
special case in the resolver — they resolve through exactly the same query as
every other grant.

**A new account's default access is its home directory and nothing else.** The
default role carries capabilities (`files.upload`, `search`) but no location, so
a fresh user can work immediately in their own space and sees nothing shared
until someone grants it. This holds however the account was created.

Example: uploading requires the `files.upload` permission **and** at least
`edit` on the target directory.

## 6. Storage and period partitioning

MinIO is addressed as an S3 disk (`league/flysystem-aws-s3-v3`) with
`use_path_style_endpoint => true`.

Object keys carry the **file's creation period**, so every version of a file
lives under one prefix:

```
files/{YYYY}/{MM}/{file_uuid}/v{n}/{original-name}
```

Keeping all versions of a file together means purging a period is a single
coherent prefix operation and can never leave v1 deleted with v3 orphaned in
another year. Because the period comes from creation date, keys are immutable.

**Upload flow:** Livewire upload → `StoreFileVersion` action → put object →
create/update `files` row → insert `file_versions` row → dispatch `ExtractText`
on the `ingest` queue.

**Download flow:** policy check → issue a short-lived **presigned MinIO URL** →
redirect. File bytes never stream through PHP.

Uploads targeting an archived period are rejected.

## 7. Text extraction

`ExtractText` runs on the dedicated `ingest` queue and selects a strategy by MIME
type. Each strategy is a small class behind a `TextExtractor` interface
(`supports(string $mime): bool`, `extract(string $path): ExtractionResult`).

| Input | Strategy | Requires |
|---|---|---|
| text/plain, csv, markdown | read directly | — |
| PDF with a text layer | `pdftotext` | poppler-utils |
| PDF with little/no text | `pdftoppm` → tesseract | poppler-utils, tesseract-ocr |
| images (png, jpg, tiff) | tesseract | tesseract-ocr |
| docx, xlsx, pptx | `ZipArchive` + XML strip | PHP zip ext |
| legacy .doc, .xls | `soffice` conversion | LibreOffice (optional build) |
| anything else | mark `unsupported` | — |

The scanned-PDF fallback triggers on a character-count threshold from the
`pdftotext` result (configurable, default 100 characters). OCR is bounded by a
page cap and a job timeout so one large scan cannot monopolise the worker.
On completion the job writes `file_texts` and dispatches `ReindexSearchDocument`.
Failures are recorded in `file_texts.error` and are retryable without re-upload.

## 8. Search

### The search index seam

Keyword search goes through a `SearchIndex` seam, the same shape as storage,
OCR and embeddings — not through Laravel Scout as originally specified.

Scout's value is swapping in a hosted engine later, but its database driver is
what the default install would actually run, and on SQLite that means `LIKE`:
no ranking, no relevance, and a full scan of every document on every query. The
configuration nearly everyone uses would be the worst one.

SQLite's FTS5 extension is compiled into the runtime and gives real BM25
ranking, which the hybrid ranking in §8a needs regardless. So:

| Implementation | Where |
|---|---|
| `Fts5SearchIndex` | embedded SQLite — BM25, no extra service |
| ~~`TsvectorSearchIndex`~~ **Unbuilt, see `item/tsvector-index` (post-v1)** | PostgreSQL — `LikeSearchIndex` covers this driver for now |
| `LikeSearchIndex` | any other driver, including PostgreSQL today: correct, unranked, a safety net |
| external engines | Typesense, Meilisearch — later, as another implementation |

### The projection table

A directory, file, or property cannot be searched in isolation: extracted text
lives on `file_texts` and property values live on `properties`, columns that do
not exist on `directories` or `files` themselves, and a document's position in
the tree is what decides who may see it. Querying those tables directly, per
entity type, on every search would mean three things to keep in sync and no
single ranked, cross-entity result set.

All search therefore runs against one projection table, `search_documents`,
built by `App\Services\SearchIndexer` and read by the `SearchIndex` seam:

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| subject_type | string | the morph class: Directory, File, or Property |
| subject_id | bigint | |
| title | string(512) | name, or property label |
| body | longtext | extracted text + flattened property values |
| directory_id | bigint null indexed | owning directory |
| ancestor_ids | json | self + every ancestor directory id, written at index time |
| period_year | smallint null | |
| period_month | tinyint null | |
| mime | string(191) null | |
| extension | string(16) null | |
| owner_id | bigint null | |
| indexed_at | timestamp | |

Unique `(subject_type, subject_id)`.

`SearchDocument` is a plain Eloquent model — `title` and `body` are ordinary
columns, read directly by each `SearchIndex` implementation (FTS5's virtual
table on SQLite, or a `LIKE`/`ILIKE` scan of these two columns elsewhere; see
below). There is no full-text indexing attribute or trait on the model itself;
each implementation is responsible for its own index structure, if it keeps
one at all.

This satisfies the requirement that directories, files, and properties all be
searchable — each gets its own rows — while keeping **one** index instead of
three to hold in sync, and it makes ranked cross-entity results possible at all.
Property values are written twice on purpose: flattened into the owning file's
or directory's `body` (so searching "Acme" returns the invoice), and as their own
rows (so admin can answer "where is this property used?").

### Maintenance

Model observers on `Directory`, `File`, and `Property`, plus the `ExtractText`
job, dispatch `ReindexSearchDocument`, which calls `SearchIndexer::index()`.
Moving a directory reindexes its subtree's `ancestor_ids`. Deleting a subject
calls `SearchIndexer::forget()`, which removes it from the index (via
`SearchIndex::forget()`, a no-op where the projection row is itself the index)
before deleting the projection row — in that order, so nothing is left
matchable with no row behind it.

### Permission filtering

`Services\Search::for()` is the only way anything searches: it resolves
`DirectoryAccess::viewableDirectoryIds()` for the current user — already
expanded down granted subtrees — once per call, and passes that id list into
`SearchIndex::search()` alongside the query. There is no Scout `query()`
callback and no engine-side expression; each `SearchIndex` implementation is
required, by the seam's own contract, to enforce the restriction itself,
inside its own query, rather than filtering a result set afterwards. Both
shipped implementations do this the same way: a predicate against the
projection row's own `directory_id` column, ANDed into the query alongside the
match clause (`d.directory_id IN (...)` in `Fts5SearchIndex`,
`whereIn('directory_id', ...)` in `LikeSearchIndex`) — not, as such, an
intersection against the `ancestor_ids` column; `ancestor_ids` is written at
index time for subtree-reindexing (see Maintenance, above) but is not read by
either implementation's filter. Because `viewableDirectoryIds()` already
contains every directory the user may reach, an equality check against the
flat `directory_id` is sufficient without consulting `ancestor_ids` at query
time. An empty viewable set or an empty parsed query short-circuits to no
results before either implementation runs a query at all. `period_year`,
`mime`, and `extension` are carried for filtering and for the eventual
faceted UI.

## 7a. OCR providers

**OCR quality is the ceiling on search quality.** Text that came out of a scan
wrong does not merely fail to match a keyword — it produces a confident
embedding of something the document never said, which is worse than no result,
because it cannot be told apart from a real one. So OCR is a seam too.

| Provider | Where it runs | Cost |
|---|---|---|
| Tesseract (default) | bundled in the image | ~50 MB, already present, CPU-cheap, offline |
| Vision model | the bundled `llama.cpp` runtime | **optional dependency**: the model is fetched on first use, not shipped |
| Azure Document Intelligence, AWS Textract, Google Document AI, Mistral OCR | remote API | a key and an egress path |

Tesseract stays the default because it is small, deterministic, offline and
already installed. It is good on clean scans and weak on complex layouts,
rotation and handwriting — which is exactly when a vision model earns its
keep.

### One runtime, two roles

The `llama.cpp` server bundled for embeddings (§8a) also serves multimodal
models through `--mmproj`, so enabling vision OCR adds a model rather than
another runtime.

The asymmetry that decides the packaging: an embedding model is tens of
megabytes and runs on any host, while a useful vision model is one to two
gigabytes and wants gigabytes of RAM. Baking that into the image would triple
its size and break small installs for a capability most will not turn on. So
**vision OCR models are an optional dependency**: downloaded on first enable
into `/data/models` on the data volume, where they survive image rebuilds and
can be removed by deleting a file.

Practical note for the build: `llama-server` is dynamically linked against the
shared objects beside it, so bundling copies the whole `/app` directory from
`ghcr.io/ggml-org/llama.cpp` — unlike MinIO's single static binary.

### Choosing a model for the hardware

Models are chosen by the operator, not fixed by doccum — but a bare list of
names is a trap. The difference between a model that runs and one that gets the
ingest worker OOM-killed mid-document is a number most people will not look up,
and the failure is ugly: a half-processed document and a worker that restarts
into the same wall.

So the picker is **hardware-aware**. A `HardwareProfile` service reads what the
container may actually use — `/sys/fs/cgroup/memory.max` under cgroup v2, the
v1 equivalent, falling back to `MemTotal` when unlimited — plus `cpu.max` and
core count, and grades every candidate:

| Grade | Meaning |
|---|---|
| Recommended | fits comfortably in available memory on this host |
| Slow here | fits, but with few cores expect minutes per page |
| Needs more memory | would not load; selectable only with an explicit override |

The catalogue records, for each model: disk size, working memory, quality tier,
licence, and where it comes from. Entries cover both roles — a bigger or
multilingual embedding model is the same kind of choice as a vision OCR model.

The hint is advice, never a gate. An operator who knows their host better than
doccum does — memory about to be freed, a swap file, a machine that is idle
overnight — can select anything in the catalogue; a grade below Recommended
simply says what to expect first. Refusing outright would be doccum overruling
someone about their own hardware on the strength of one number.

Concretely, against usable memory `U` and a model's working requirement `W`:

| Condition | Grade | What the operator is told |
|---|---|---|
| `W × 1.25 ≤ U` and cores ≥ 4 | Recommended | "Fits comfortably on this host." |
| `W × 1.25 ≤ U` and cores < 4 | Slow here | "Will run, but expect roughly a minute per page on 2 cores." |
| `W ≤ U` | Tight | "Fits with little headroom; OCR may slow other work." |
| `W > U` | Needs more memory | "Needs ~3.0 GB, this host has 1.8 GB available. Selecting it anyway risks the ingest worker being stopped mid-document." |

The 25% headroom is there because the worker is not alone: extraction, the web
process and the database share the container, and a model sized to exactly fit
leaves nothing for them.

**Available memory matters more than total.** A 16 GB host with 2 GB free will
not run a 3 GB model, and reporting "16 GB" would be a lie of omission, so the
picker shows both.

With Ollama as the provider the operator may name any model Ollama can pull;
doccum shows its guidance for catalogue entries it recognises and stays quiet
rather than guessing about the rest. With the bundled runtime the catalogue is
the list, because doccum has to know the download URL and the projector file.

### Strategy selection

The existing extractor chain (§7) is unchanged for anything with a text layer:
a PDF that already contains text is never OCR'd, whichever provider is
configured. The provider applies only where OCR actually happens — scans and
images — so switching to a vision model costs nothing on the majority of
documents.

Re-running OCR on already-ingested documents is `doccum:extract:redo`, which
re-queues extraction and, when embeddings are enabled, the embedding that
depends on it.

## 8a. Semantic search

Keyword search finds documents that use the words you typed. Semantic search
finds the ones that mean what you meant. doccum does both, and the second is
built on two seams shaped like the storage seam.

### Vector index: follows the database

| Database | Index | Cost |
|---|---|---|
| Embedded (SQLite) | `sqlite-vec` | a 60 KB loadable extension, no process |
| PostgreSQL | `pgvector` | an extension, present in `pgvector/pgvector` images |

Both sit behind one `VectorIndex` service, so nothing above it knows which is in
use. SQLite keeps its decisive advantage — the embedded database stays a library
with no process to supervise — because the vector extension is loadable rather
than a server.

Loading it needs `Pdo\Sqlite::loadExtension()`, added in PHP 8.3. Laravel's
SQLite connector builds a plain `PDO`, so the connector is overridden to use
`PDO::connect()`, which returns the driver subclass.

### Embedding generator: local or remote, one client

**Embeddings are generated over an OpenAI-compatible `/v1/embeddings` endpoint,
whatever produces them.** That is the whole trick: the bundled local model and a
remote API differ by a base URL and an optional key, not by a code path — the
same lesson as embedded storage speaking S3.

| Provider | Endpoint |
|---|---|
| Embedded (default) | `llama.cpp` server on `127.0.0.1`, bundled |
| Ollama | a local or remote Ollama host |
| OpenAI / Voyage / Cohere | the vendor's API with a key |
| Custom | any OpenAI-compatible endpoint |

The embedded generator is the `llama.cpp` server binary copied from
`ghcr.io/ggml-org/llama.cpp` (published for amd64 and arm64) plus a small
quantised GGUF embedding model pinned by revision. That is tens of megabytes,
not hundreds: an embedding model is far smaller than a generative one. It is
supervised exactly like MinIO, bound to localhost, never published, and gated by
`DOCCUM_EMBEDDED_EMBEDDINGS` so the worker containers do not each run one.

### Chunking, and why embeddings are per passage

**A document does not get one embedding.** Averaging fifty pages into a single
vector produces a point that means nothing in particular: the passage that
actually answers the query is diluted by everything around it, and long
documents rank worse the more they contain. Text is therefore split into
overlapping chunks — a few hundred tokens each, overlapping so a passage split
across a boundary survives in one piece — and each chunk is embedded
separately.

`search_chunks` holds them: `search_document_id`, `ordinal`, `text`,
`token_count`, `embedding`, `embedding_model`, `embedded_at`. A hit is a chunk;
results are grouped back to their document, keeping the best-scoring passage as
the snippet — which is also what makes a useful preview, because it is the part
that matched.

### Asynchronous by construction

Extraction, chunking and embedding all run on the `ingest` queue, never in the
request. An upload returns as soon as the bytes are stored; search freshness
lags by however long the pipeline takes, and that is the correct trade — a user
waiting on OCR of a 300-page scan before their upload completes is the wrong
failure.

Four properties make that safe rather than merely deferred:

- **Resumable.** Each chunk records its own `embedded_at`, so a job killed at
  chunk 400 of 900 re-embeds 500 on restart, not 900. Long documents are
  precisely the ones most likely to be interrupted.
- **Batched and bounded.** Chunks go to the provider in batches with limited
  concurrency and backoff on rate limiting, so a remote provider's 429 slows
  ingestion instead of failing it, and a local model is not asked for more
  parallelism than the host has cores.
- **Idempotent.** Re-running embeds only what is missing or stale. Re-ingesting
  a document, re-running after a crash, and backfilling after enabling a
  provider are all the same code path.
- **Never blind.** Existing vectors stay searchable while new ones are
  generated. A model change writes the new dimension alongside, and search
  switches over once coverage is complete — rather than leaving the corpus
  unsearchable for the hours a large backfill takes.

### The scheduled sweep is the authority

Embedding is driven by a scheduled task, not only by a job dispatched at upload.
`doccum:embeddings:sweep` runs on the scheduler every few minutes, claims a
bounded slice of whatever is unembedded or stale, and processes it.

That inversion matters. If embedding only happened reactively, then every way a
job can be lost — retries exhausted, the provider down for an hour, the worker
killed mid-batch, a model enabled after the documents arrived — leaves
documents permanently invisible to semantic search with nothing to notice or
correct it. A user would simply never find a file and have no way to know why.
With the sweep as the authority, the dispatched job is an optimisation that
makes embedding prompt, and its failure costs latency rather than correctness.

The bound is what makes it safe on a small host. Each run takes a configured
number of chunks rather than everything outstanding, so enabling a provider on
an instance with 100,000 existing documents drains steadily over hours instead
of saturating CPU and starving the web process. The same mechanism covers
first-time backfill, recovery after an outage, and re-embedding after a model
change — there is no separate backfill path to get wrong, and
`doccum:embeddings:backfill` simply runs the sweep to completion for an operator
who would rather not wait.

Progress is observable: how many chunks are pending, how many stale, and what
the last run did — because a silent background process that has quietly done
nothing for a week is indistinguishable from one with nothing to do.

### Hybrid ranking

Keyword and semantic search answer different questions and fail differently:
exact identifiers, names and numbers are keyword's strength and embeddings'
weakness, while paraphrase is the reverse. Results from both are combined by
reciprocal rank fusion rather than by comparing scores directly, because a BM25
score and a cosine distance are not on the same scale and any threshold that
appears to work is a coincidence of one corpus.

With no embedding provider configured, fusion degrades to keyword alone and the
search UI is unchanged.

### Storage and invalidation

`search_documents` carries `embedding`, `embedding_model` and
`embedding_dimensions` alongside the text. Recording the model is not
bookkeeping: **different models produce different dimensions and incompatible
vector spaces** — 384 for `bge-small`, 1536 for OpenAI's small model — so
changing provider invalidates every stored vector. doccum detects the mismatch,
rebuilds the vector column at the new width, and backfills through
`doccum:embeddings:backfill` rather than silently comparing vectors that mean
nothing to each other.

Embedding happens on the `ingest` queue, after extraction, so a slow model
delays search freshness rather than uploads.

### Sequencing

Keyword search ships first and stands alone; semantic search is the plan after
it. The schema and both seams are designed in from the start so that is an
additive change rather than a migration of everything already indexed.

## 9. Archive and purge

A period is a first-class row with a lifecycle: **open** → **archived**
(read-only, uploads rejected) → **purged**.

Commands:

- `doccum:close-periods` — scheduled. Rolls up finished months and years:
  counts files and bytes, writes the `archive_periods` row, marks it archived.
  This is the automatic rollup.
- `doccum:purge-period {year} {month?}` — deletes database rows and the MinIO
  prefix. Defaults to `--dry-run`, reporting exactly what would be removed and
  how many bytes it reclaims.
- `doccum:purge-expired` — applies `config('doccum.retention.purge_after_years')`.

Three guards, because purge is the only irreversible operation in the system:

1. A period cannot be purged unless `archived_at` is set **and** it is past the
   retention window.
2. Any file or directory flagged `legal_hold` blocks its period. Purge reports
   the blockers and refuses rather than partially deleting.
3. Automatic purging is **off by default**. The scheduler reports candidates;
   actual deletion requires an explicit config flag.

Purging cascades to `file_versions`, `file_texts`, `properties`, and
`search_documents`. Directories survive — a purged year leaves its folder
structure standing and empty.

Trash purging (soft-deleted rows past a retention window) uses the same machinery.

## 10. User-facing surface

### Application shell

Full-width, Dropbox-style. No page max-width or centred container — content
fills the viewport at every breakpoint. Tailwind 4 with Flux free components.

**Topbar** — full width, fixed, one row, no sidebar above it:

- **Left:** text wordmark `doccum`, lowercase, set in type rather than an image
  or icon, immediately followed by a small muted version pill (`v0.1.0`) read
  from `config('doccum.version')`.
- **Right:** primary navigation — **Home**, **Files**, **Settings**,
  **My Account**. Settings is hidden from users lacking admin permissions.
  My Account is an avatar/initials menu (profile, password, sessions, log out).

**Main area** — everything below the topbar, also full width.

The Files view is a three-pane Dropbox layout:

- **Left sidebar** — the directory tree, collapsible and resizable, remembering
  its width per user. Only branches the user can `view` are rendered.
- **Centre pane** — breadcrumb above a dense row-per-item list: mime icon, name,
  owner, modified, size. Multi-select with shift/ctrl, bulk move/delete, column
  sorting, drag-and-drop upload anywhere on the pane.
- **Right panel** — opens on selection: properties, version history, download,
  replace. Collapsible; hidden when nothing is selected.

### Destinations

- **Home** — dashboard: recent files, recent activity, storage consumed by
  period, quick search entry.
- **Files** — the browser above. Default landing after login.
- **Search** — results across directories, files, and properties with a content
  snippet, filtered by type, mime, period, and property values. Reached from
  the topbar search field and from Home.
- **Settings** — users and role assignment, roles and permissions, property
  definitions, archive periods, instance settings. Each section gated by its
  Spatie permission.
- **My Account** — profile, password, sessions.
- **Trash** — restore or purge soft-deleted items. Reached from Files.

### Authentication

Auth comes from the Livewire starter kit: login, password reset, profile,
extended with the required **username** field on registration and admin user
creation. Login accepts either username or email.

A fresh install with zero users redirects to a one-time, middleware-guarded
**first-run setup screen** that creates the first admin and names the instance;
the route becomes unreachable once a user exists. No default credentials exist at
any point.

**Public signup is a setting** (`auth.public_signup`), **off by default** — a
self-hosted install should not become writable by strangers merely by being
reachable. When an operator enables it under Settings, the registration route
becomes available and new accounts receive the role named by `auth.default_role`.
When it is off the route returns 404 rather than a disabled form, so the instance
does not advertise an entry point it will not honour.

However a user arrives, they get a home directory named for their username and
`manage` on it, and no other access (§5) — so an account is useful the moment it
exists without exposing anything shared. The Files sidebar pins **Home** above
the shared tree.

## 10a. First-run installer

doccum configures itself through the browser rather than through files. The
first-run screen is a three-step installer — database, storage, then the admin
account, in that order so migrations and the admin land in the database the
operator chose.

Configuration is split between two stores by what each can bootstrap.

**The encrypted file holds only the database connection** — the one genuine
chicken-and-egg problem. By default `/data/runtime.json`
(`DOCCUM_RUNTIME_CONFIG` overrides the path), mode `0600`, excluded from the
image. It cannot live under `storage/`: that directory is baked into the
container image rather than mounted, so credentials written there would
disappear on the next rebuild.

**Everything else lives in the `settings` table**, storage credentials included,
with secrets encrypted at rest through `Settings::setSecret()`. Nothing resolves
a disk during boot, so storage configuration can safely come from the database.
This keeps the file minimal, gives operator settings one source of truth, and
means a database backup carries the storage configuration with it.

The payload is encrypted with `APP_KEY`. This is defence in depth, not a
security boundary — `APP_KEY` is persisted on the same volume, so anyone who can
read the volume can read both. What it does prevent is credentials appearing in
plaintext inside a backup, a support bundle, a copied file, or a log.

`RuntimeConfigServiceProvider` is registered first. It applies the database
override in `register()`, before anything resolves a connection, reading the
file directly with no container dependencies — because the credentials it
carries are the ones the container would otherwise need. Storage settings are
applied in `boot()`, once the database is available.

**Precedence: the runtime file wins over environment variables.** Compose values
are a starting point; a choice made in the installer is the operator's decision
and must survive. Settings shows which values are runtime-managed and how to
clear them.

Each step probes before it saves — a real connection for the database, a real
PUT and DELETE for storage — so a bad credential fails at the form rather than
at first use.

**If the file exists but cannot be decrypted or its database cannot be reached,
the app does not fall back to environment defaults.** It serves an explicit
error and refuses to re-run the installer. Falling back would present an empty
database that looks like a fresh install, inviting an operator to reinstall over
live data.

### Attaching to an existing instance

Because everything except the database connection lives in the database,
pointing a new container at an existing doccum database restores the whole
instance — storage credentials, instance name, users, roles, grants — with no
further configuration. Moving a deployment is giving the installer the database
credentials.

Two things make that safe rather than surprising.

**The installer detects a populated database** and switches from install to
attach: it runs pending migrations (the upgrade path), then goes straight to the
login screen. It does not offer to create an admin, and it never overwrites
existing settings. Treating a populated database as a fresh install is how an
installer destroys a live deployment.

**`APP_KEY` must come with the database.** Secrets in `settings` are encrypted
with it, as are Fortify's two-factor secrets, and a fresh container generates a
new key when `/data/.env` is absent. A `instance.key_check` canary is written at
install time; on attach, failure to decrypt it means the key does not match, and
the installer says so plainly and refuses rather than presenting a
half-functional instance. Recovering means supplying the original `APP_KEY`
through the environment or restoring `/data/.env`.

`doccum:config:show` prints the effective runtime configuration with secrets
masked; `doccum:config:reset` removes the file and returns the instance to its
environment configuration.

## 11. API

Laravel Sanctum v4 personal access tokens. All routes under `/api/v1`, guarded by
`auth:sanctum`, rate-limited per token.

### Authorisation: three gates

A token's effective rights are the **intersection** of three things:

1. The token's **abilities** — what this particular token may do.
2. The owning user's **Spatie permissions** — what that person may ever do.
3. The **directory ACL** — where they may do it.

A token can therefore never exceed its owner, and revoking a user's role
immediately narrows every token they hold. Abilities are coarse scopes:
`directories:read`, `directories:write`, `files:read`, `files:write`,
`files:delete`, `properties:read`, `properties:write`, `search`.

Because policies are shared with the UI, an API response can never expose a
document the same user would not see when browsing.

Tokens are managed under **My Account**: create (name, abilities, optional
expiry), list with last-used timestamps, revoke. The plaintext token is shown
exactly once, on creation.

### Surface

Content operations only. Admin — users, roles, property definitions, archive
periods — stays UI-only, so no token can reach `purge`.

```
GET    /api/v1/directories                     ?parent_id= for children
POST   /api/v1/directories
GET    /api/v1/directories/{id}
PATCH  /api/v1/directories/{id}                rename / move
DELETE /api/v1/directories/{id}                soft delete

GET    /api/v1/directories/{id}/files
POST   /api/v1/files/upload-url                → presigned PUT + upload_id
POST   /api/v1/files                           commit an uploaded object
GET    /api/v1/files/{id}
PATCH  /api/v1/files/{id}                      rename / move
DELETE /api/v1/files/{id}                      → trash
GET    /api/v1/files/{id}/download-url         → presigned GET
GET    /api/v1/files/{id}/versions
POST   /api/v1/files/{id}/versions/upload-url
GET    /api/v1/files/{id}/text                 extraction status + text

GET    /api/v1/property-definitions           read-only
PUT    /api/v1/directories/{id}/properties
PUT    /api/v1/files/{id}/properties

GET    /api/v1/search                          ?q=&type=&mime=&period=&attr[key]=

GET    /api/v1/trash
POST   /api/v1/trash/{type}/{id}/restore
```

### Upload flow

Bytes never pass through PHP, so there is no `upload_max_filesize` ceiling and a
large upload never occupies a worker:

1. `POST /files/upload-url` with `directory_id`, `name`, `mime`, `size`. Policy
   check (`files.upload` **and** `edit` on the directory) runs here. Returns a
   short-lived presigned PUT URL against a staging prefix, plus a signed
   `upload_id` binding directory, name, and size.
2. Client PUTs the bytes straight to MinIO.
3. `POST /files` with the `upload_id`. The server verifies the object exists and
   that size and checksum match what was declared, server-side copies it from
   staging to its period key (§6), creates the `files` and `file_versions` rows,
   and dispatches `ExtractText`.

Staging is a separate prefix (`uploads/{uuid}`) so an abandoned step 2 never
leaves junk inside a period prefix that archive and purge reason about. A
scheduled `doccum:sweep-uploads` clears stale staging objects, with a MinIO
lifecycle rule on that prefix as a backstop.

Downloads mirror this: `GET /files/{id}/download-url` runs the policy check and
returns a short-lived presigned GET.

### Conventions

Laravel API Resources with a `data` / `meta` envelope, cursor pagination on all
listings, and Laravel's standard validation error shape. `/api/v1` is versioned
from day one; breaking changes go to `/api/v2` rather than mutating v1.

## 12. Documentation

Two audiences, because this is software other people install.

**Integrators** — an OpenAPI reference at `docs/api/openapi.json`, committed to
the repository and built by `app/Support/OpenApi/OpenApiSpecBuilder.php` via the
`doccum:generate-openapi` command. `tests/Feature/Api/OpenApiContractTest.php`
asserts the committed spec and the live route set match **in both directions** —
every registered `api.v1` route is documented, and no documented route has
ceased to exist — so a drifting API fails the build rather than the reader. The
test includes a self-check that its undocumented-route arm actually fires.

This paragraph previously specified generation by `dedoc/scramble` and an
interactive `/docs/api` route outside production. Neither shipped, and
`decision/0118` records why both were retired rather than built.

The short version: the stated *reason* for Scramble was "generating from types
rather than annotations means the reference cannot drift from the code", and
the contract test delivers that property more directly — it fails the build on
drift in either direction, where generation merely regenerates and stays
silent. Serving interactively was retired on two facts rather than a
preference: `docs` is in `.dockerignore`, so the committed spec never reaches
the image, and the route-descriptor logic the builder needs is private to a
console command. Serving it at runtime therefore costs either the whole `docs/`
tree in the image or a new seam extracted for one page, to give a self-hoster a
copy of a file that already ships, pinned to the same version, in the
repository they installed from.

**Self-hosters** — Markdown under `docs/`:

- Quick start — the single container with `docker run`, first-run setup screen,
  first upload; `docker compose up` as the fuller alternative.
  ~~`docker compose up`~~ alone was **superseded**: `README.md` calls the single
  `docker run` "the whole installation" and `quick-start.md` leads with it, which
  is the honest order — the container is the product, and the compose stack is
  the option for anyone who wants sibling services.
- Configuration reference — every environment variable, with local and remote
  columns side by side (§13).
- Storage — MinIO setup, moving to S3 or a remote MinIO.
- Search — running on the default database driver and the upgrade path to a
  dedicated engine.
- Operations runbook — archive and purge, legal holds, the dry-run workflow.
- Backup and restore, upgrading, troubleshooting.

Plus `README.md` (what it is, screenshot, three-line quick start), `LICENSE`
(MIT), `CONTRIBUTING.md`, and `SECURITY.md`.

## 13. Infrastructure

Base image **`serversideup/php:8.5-frankenphp-bookworm`** for `app`, `worker`,
`worker-ingest`, and `scheduler` — one image, different commands. A thin
`Dockerfile` layers on `poppler-utils`, `tesseract-ocr` plus language data, the
built Vite assets, and vendor. Debian rather than Alpine because tesseract
language packs are better supported there. LibreOffice sits behind
`WITH_OFFICE=false`; the pure-PHP extractor already covers modern Office formats.

The base image supplies `AUTORUN_ENABLED` / `AUTORUN_LARAVEL_MIGRATION_MODE`
(migrations at container start), opcache configuration, healthchecks, and
non-root execution, so none of that is written here.

### Services

| Service | Profile | Drop when |
|---|---|---|
| `app` (FrankenPHP) | — | never |
| `worker` (default queue) | — | never |
| `worker-ingest` (OCR; own concurrency and memory) | — | never |
| `scheduler` (`schedule:work`) | — | never |
| `minio` + `minio-init` | `storage` | using S3 or remote MinIO |
| `redis` | `cache` | not needed by default; opt in for speed |
| `postgres` | `db` | using SQLite (default) or managed Postgres |
| search engine | `search` | engine undecided, or hosted |

`worker-ingest` is separate from `worker` so a 300-page OCR job cannot block a
mail send or a reindex.

### Zero-configuration boot

`docker compose up` must require no command, no file edit, and no external
account:

- **SQLite by default** on a named volume — no database container at all.
- **The `SearchIndex` seam on its embedded default by default** (`Fts5SearchIndex`
  on SQLite, `LikeSearchIndex` elsewhere; see §8) — no search service required.
  Typesense/Meilisearch arrive behind the `search` profile.
- **Queue and cache on the `database`/`file` drivers by default** — no Redis
  container is required at all; `cache` profile opts into Redis when wanted.
- **MinIO auto-provisions** bucket and policy via a `minio-init` one-shot.
- **`APP_KEY` self-generates** on first boot when absent; `.env` is optional.
- **Healthchecks on the three worker services**, so `docker compose ps`
  distinguishes a container that is running from one that is actually working:
  `worker`, `worker-ingest` and `scheduler` each `pgrep` for their own command,
  because the base image's healthcheck curls HTTP and a worker never serves any.
  `app` keeps the base image's check; the profile services keep their own images'.
  ~~with `depends_on: condition: service_healthy` so start ordering is the
  orchestrator's concern, not a README's~~ — **superseded**, and by the very next
  paragraph, which has always said the opposite. `compose.yaml` declares no
  `depends_on` anywhere. The healthchecks are real; the ordering clause never was.
- A **published image** on ghcr.io so self-hosters pull rather than build.

Default stack: `app` + 3 workers. ~~`minio` + `minio-init`~~ — **superseded**:
both carry `profiles: ["storage"]` in `compose.yaml`, so neither starts unless
that profile is selected. The embedded stack runs MinIO inside the `app`
container under supervisor instead, which is what makes the default stack
database-free *and* storage-container-free.

No service declares `depends_on`. Nothing touches storage, cache, or search
during boot, so each container starts independently and detaching one is simply
not starting it — a `depends_on` chain would make "point storage at a remote"
require editing the compose file rather than the environment.

### Portability

**No application code ever names a container.** Every boundary is environment
driven: `DB_*`, `FILESYSTEM_DISK` / `AWS_ENDPOINT` / `AWS_BUCKET`, `REDIS_HOST`,
`QUEUE_CONNECTION`, `MAIL_*`. The `SearchIndex` seam has no driver setting of
its own — it follows `DB_CONNECTION` (§8) — so a hosted engine arrives as a
new implementation of the seam, not a new environment variable. Moving to
managed Postgres, real S3, and hosted search is editing `.env` and removing
profiles from `COMPOSE_PROFILES` — no code change and no rebuild.
~~`.env.example` documents the local and remote columns side by side.~~
**Superseded**: `.env.example` is the Laravel starter kit's file plus a storage
block. The local-and-remote mapping this sentence describes lives in
`docs/self-hosting/storage.md`, which is where an operator making that change
actually looks.

~~`compose.override.yml` adds development conveniences (source bind-mount, Vite
dev server) without touching the production-shaped base file.~~ **Superseded**:
no such file exists, and nothing has needed one — development happens against
`php artisan serve` and `npm run dev` rather than against the compose stack. If
it is ever added, this paragraph is the design it should follow.

## 14. Testing

Pest, test-driven: tests precede implementation for each unit.

**Unit** — `DirectoryAccess` inheritance and role resolution; materialised path
maintenance on create/move/delete; each `TextExtractor` strategy against
fixtures; the scanned-PDF fallback threshold; purge guards; period derivation
and object-key construction.

**Feature** — one suite per Livewire component, with `Storage::fake` for MinIO,
the real `SearchIndex` implementation against the test SQLite connection, and
`Queue::fake`. Policy coverage asserts that a
user without a grant sees neither the directory, its files, nor any search
result referencing them.

**Integration** — the archive/purge commands end to end against a seeded tree,
asserting dry-run reports match actual effects and that legal holds block.

**API** — a suite per endpoint asserting the three-gate intersection: a token
missing an ability is refused even when its owner holds the permission; a token
with the ability is still refused where the directory ACL denies; a revoked role
immediately narrows an existing token. Plus the presigned upload flow end to end
(url → PUT → commit), including rejection when the committed object's size or
checksum disagrees with what was declared.

**Contract** — a test asserting the committed `openapi.json` matches the current
routes, so an API change that outruns its documentation fails CI.

## 15. Milestones

1. Scaffold — Laravel 13, Livewire 4 starter kit with Flux free, Pest, Spatie,
   MinIO disk, Docker image and compose, MIT LICENSE. (Scout was scaffolded
   in the original plan; it was dropped before shipping — see §2 and §8.)
2. Tree and data model — directories, files, migrations, factories, path logic.
3. Access control — `DirectoryAccess`, policies, Spatie roles and seeded
   permissions, first-run setup screen.
4. Storage — upload, versions, presigned download, trash and restore.
5. Properties — definitions admin, typed values, inline editing.
6. Extraction — strategies, `ExtractText` job, OCR fallback.
7. Search — `search_documents` projection, indexer, permission-filtered UI.
8. Archive and purge — period rollup, commands, guards, admin screen.
9. Admin surface — users, roles, periods; polish.
10. API — Sanctum tokens and ability model, token management UI, content
    endpoints, presigned upload/download flow, staging sweep.
11. Documentation — a committed `openapi.json` with a two-directional contract
    test (`decision/0118` retired the Scramble dependency and the `/docs/api`
    route this milestone used to name), self-hosting guide, README,
    CONTRIBUTING, SECURITY.

## 16. Deferred

- Search engine selection (Typesense vs Meilisearch) once real data exists.
- ~~SQLite FTS5 virtual table as an optimisation over the LIKE-based default.~~
  Superseded — shipped as `Fts5SearchIndex`, the default `SearchIndex` on
  SQLite; see §8. No longer deferred.
- Aggregate statistics rollups (counts and bytes per directory/owner/mime).
- Version thinning policies and extracted-text pruning for archived files.
- Multi-tenancy, antivirus scanning, check-out locking, approval workflows.
- Admin operations over the API, and webhooks for upload/extraction events.
- OAuth2 / client-credentials grants for machine-to-machine integrations.

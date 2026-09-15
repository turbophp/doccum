# doccum — Design

Date: 2026-09-15
Status: Approved (pending final spec review)

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
| Search engine | Laravel Scout, engine deferred | Code against Scout's interface; ship on the database driver, adopt Typesense/Meilisearch once there is real data to tune against. |
| Attributes | Admin-defined definitions, typed values | Validation, consistent filters, working range/date sorting. |
| Access control | Spatie roles + per-directory ACL with subtree inheritance | Capability and location are separate questions. |
| File lifecycle | Versions + soft-delete trash | Standard DMS expectation; overwrites recoverable. |
| UI | Laravel 13 + Livewire 4 starter kit + Flux UI (free) | Auth scaffolding and component library out of the box. |
| Rollups | Period-partitioned storage + bulk purge | Purging a period is a prefix operation, not a per-file hunt. |
| Period source | File creation (upload) date | Immutable; object keys never relocate. |
| Tenancy | Single org | No tenant key on every table. |
| Signup | Operator setting, default off; username required | Safe by default for a self-hosted install. |
| Home directories | One per user, named for the username, owner holds `manage` | Personal space with no second permission concept. |
| Default access | Home directory only | A new account can reach nothing shared until granted. |
| Attribute search | Own index entries + flattened into parents | Both "find the invoice" and "where is this attribute used". |
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
attribute_definitions ── attributes (morph: Directory | File)
archive_periods
search_documents (projection: Directory | File | Attribute)
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

Unique `(parent_id, name)` among non-trashed rows.

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

Unique `(directory_id, name)` among non-trashed rows.
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

### `attribute_definitions`

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

### `attributes`

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| attribute_definition_id | bigint fk | |
| attributable_type | string | Directory or File |
| attributable_id | bigint | |
| value_string | string(1024) null | string, text, select |
| value_number | decimal(20,6) null | number |
| value_date | date null | date |
| value_boolean | boolean null | boolean |
| timestamps | | |

Exactly one value column is populated, chosen by the definition's `data_type`.
Typed columns rather than a single stringly-typed `value` so numeric ranges and
date ordering work in SQL.

Index `(attributable_type, attributable_id)`, and
`(attribute_definition_id, value_string)` for lookup by value.
Unique `(attribute_definition_id, attributable_type, attributable_id)`.

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
`directories.create`, `directories.manage`, `attributes.manage`,
`users.manage`, `periods.manage`, and `directories.view-all` (admin bypass of
layer 2).

**Layer 2 — `directory_access`.** Where may they perform it? Levels are ordered
`view` < `edit` < `manage`:

- **view** — list the directory, see its files, download them.
- **edit** — upload, rename, set attributes, soft-delete files.
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

### The projection table

Laravel Scout's database engine resolves every key returned by
`toSearchableArray()` through `qualifyColumn()` — each key must be a real column
on the model's own table. Extracted text lives in `file_texts` and attribute
values live in `attributes`, so making `File` directly `Searchable` would
generate SQL against columns that do not exist, and the zero-config default
would fail immediately.

All search therefore runs against one projection table, `search_documents`:

| Column | Type | Notes |
|---|---|---|
| id | bigint pk | |
| searchable_type | string | Directory, File, or Attribute |
| searchable_id | bigint | |
| title | string(512) | name, or attribute label |
| body | longtext | extracted text + flattened attribute values |
| directory_id | bigint null indexed | owning directory |
| ancestor_ids | json | self + every ancestor directory id |
| period_year | smallint null | |
| period_month | tinyint null | |
| mime | string(191) null | |
| extension | string(16) null | |
| owner_id | bigint null | |
| indexed_at | timestamp | |

Unique `(searchable_type, searchable_id)`.

`SearchDocument` is the model carrying Scout's `Searchable` trait. `title` and
`body` are real columns, so the database engine works out of the box; on MySQL
and Postgres they can be marked `#[SearchUsingFullText]` for native full-text.

This satisfies the requirement that directories, files, and attributes all be
searchable — each gets its own rows — while keeping **one** index instead of
three to hold in sync, and it makes ranked cross-entity results possible at all.
Attribute values are written twice on purpose: flattened into the owning file's
or directory's `body` (so searching "Acme" returns the invoice), and as their own
rows (so admin can answer "where is this attribute used?").

### Maintenance

Model observers on `Directory`, `File`, and `Attribute`, plus the `ExtractText`
job, dispatch `ReindexSearchDocument`. Moving a directory reindexes its subtree's
`ancestor_ids`. Deleting cascades to the projection and removes the Scout entry.

### Permission filtering

Every document carries `ancestor_ids`. A search intersects that array against
`DirectoryAccess::viewableDirectoryIds()`. The expression evaluates engine-side
on Typesense/Meilisearch and through Scout's `query()` callback on the database
driver, so deferring the engine choice costs nothing and no result can leak a
document the user cannot reach. `period_year`, `mime`, and `extension` are
carried for filtering and for the eventual faceted UI.

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

Purging cascades to `file_versions`, `file_texts`, `attributes`, and
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
- **Right panel** — opens on selection: attributes, version history, download,
  replace. Collapsible; hidden when nothing is selected.

### Destinations

- **Home** — dashboard: recent files, recent activity, storage consumed by
  period, quick search entry.
- **Files** — the browser above. Default landing after login.
- **Search** — results across directories, files, and attributes with a content
  snippet, filtered by type, mime, period, and attribute values. Reached from
  the topbar search field and from Home.
- **Settings** — users and role assignment, roles and permissions, attribute
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
`files:delete`, `attributes:read`, `attributes:write`, `search`.

Because policies are shared with the UI, an API response can never expose a
document the same user would not see when browsing.

Tokens are managed under **My Account**: create (name, abilities, optional
expiry), list with last-used timestamps, revoke. The plaintext token is shown
exactly once, on creation.

### Surface

Content operations only. Admin — users, roles, attribute definitions, archive
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

GET    /api/v1/attribute-definitions           read-only
PUT    /api/v1/directories/{id}/attributes
PUT    /api/v1/files/{id}/attributes

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

**Integrators** — an OpenAPI reference generated by `dedoc/scramble` from route
signatures, FormRequests, and API Resources. Generating from types rather than
annotations means the reference cannot drift from the code. Served interactively
at `/docs/api` outside production, and exported to an `openapi.json` committed in
CI. A test asserts the committed spec matches the current routes, so a drifting
API fails the build rather than the reader.

**Self-hosters** — Markdown under `docs/`:

- Quick start — `docker compose up`, first-run setup screen, first upload.
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

Base image **`serversideup/php:8.4-frankenphp-bookworm`** for `app`, `worker`,
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
- **Scout on the `database` driver by default** — no search service required.
  Typesense/Meilisearch arrive behind the `search` profile.
- **Queue and cache on the `database`/`file` drivers by default** — no Redis
  container is required at all; `cache` profile opts into Redis when wanted.
- **MinIO auto-provisions** bucket and policy via a `minio-init` one-shot.
- **`APP_KEY` self-generates** on first boot when absent; `.env` is optional.
- **Healthchecks with `depends_on: condition: service_healthy`** so start
  ordering is the orchestrator's concern, not a README's.
- A **published image** on ghcr.io so self-hosters pull rather than build.

Default stack: `app` + 3 workers + `minio` + `minio-init`.

No service declares `depends_on`. Nothing touches storage, cache, or search
during boot, so each container starts independently and detaching one is simply
not starting it — a `depends_on` chain would make "point storage at a remote"
require editing the compose file rather than the environment.

### Portability

**No application code ever names a container.** Every boundary is environment
driven: `DB_*`, `FILESYSTEM_DISK` / `AWS_ENDPOINT` / `AWS_BUCKET`, `REDIS_HOST`,
`SCOUT_DRIVER` and engine host, `QUEUE_CONNECTION`, `MAIL_*`. Moving to managed
Postgres, real S3, and hosted search is editing `.env` and removing profiles from
`COMPOSE_PROFILES` — no code change and no rebuild. `.env.example` documents the
local and remote columns side by side.

`compose.override.yml` adds development conveniences (source bind-mount, Vite dev
server) without touching the production-shaped base file.

## 14. Testing

Pest, test-driven: tests precede implementation for each unit.

**Unit** — `DirectoryAccess` inheritance and role resolution; materialised path
maintenance on create/move/delete; each `TextExtractor` strategy against
fixtures; the scanned-PDF fallback threshold; purge guards; period derivation
and object-key construction.

**Feature** — one suite per Livewire component, with `Storage::fake` for MinIO,
Scout's `collection` driver, and `Queue::fake`. Policy coverage asserts that a
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
   Scout, MinIO disk, Docker image and compose, MIT LICENSE.
2. Tree and data model — directories, files, migrations, factories, path logic.
3. Access control — `DirectoryAccess`, policies, Spatie roles and seeded
   permissions, first-run setup screen.
4. Storage — upload, versions, presigned download, trash and restore.
5. Attributes — definitions admin, typed values, inline editing.
6. Extraction — strategies, `ExtractText` job, OCR fallback.
7. Search — `search_documents` projection, indexer, permission-filtered UI.
8. Archive and purge — period rollup, commands, guards, admin screen.
9. Admin surface — users, roles, periods; polish.
10. API — Sanctum tokens and ability model, token management UI, content
    endpoints, presigned upload/download flow, staging sweep.
11. Documentation — Scramble reference and committed `openapi.json`,
    self-hosting guide, README, CONTRIBUTING, SECURITY.

## 16. Deferred

- Search engine selection (Typesense vs Meilisearch) once real data exists.
- SQLite FTS5 virtual table as an optimisation over the LIKE-based default.
- Aggregate statistics rollups (counts and bytes per directory/owner/mime).
- Version thinning policies and extracted-text pruning for archived files.
- Multi-tenancy, antivirus scanning, check-out locking, approval workflows.
- Admin operations over the API, and webhooks for upload/extraction events.
- OAuth2 / client-credentials grants for machine-to-machine integrations.

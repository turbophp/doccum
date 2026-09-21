# v1 audit

The audit `item/v1-audit` asks for: a walk of spec milestones 1 to 11 and of
the backlog, against the code rather than against memory.

This file is the record. It is written to be **falsifiable**: every tick cites
something a reader can run or open, and every gap names what is missing rather
than what is planned. Where a claim could not be checked here, it says so and
names where it is checked instead.

Audited at `518275a`, against
`docs/superpowers/specs/2026-09-15-doccum-design.md` §15.

## How each tick was earned

Three kinds of evidence appear below, and they are not equally strong:

- **Mechanical** — a comparison a script did, whose output is quoted. The
  strongest, because it asks the question the code answers rather than the
  question the auditor thought of (`decision/0114`).
- **Cited** — a named file, route or job that exists, quoted by path.
- **CI** — a check name whose conclusion is read on a `main` push run. Not
  re-run here: this environment has no `vendor/`, so `pint`, `phpstan` and
  the suite are verified in CI and nowhere else (`decision/0093`).

## Milestones

### 1. Scaffold — PASS

Laravel `^13.17`, Livewire `^4.1`, Flux `^2.13.1`, Pest `^5.2`,
`spatie/laravel-permission ^8.3`, `league/flysystem-aws-s3-v3 ^3.0` — all in
`composer.json`. `Dockerfile` and `compose.yaml` both present; `LICENSE` is
MIT.

Two deviations from the milestone's own wording, both already reconciled in
the spec text: Scout was scaffolded in the original plan and dropped before
shipping (§2, §8), and the compose file is `compose.yaml`, not
`docker-compose.yml`.

### 2. Tree and data model — PASS

12 models (`app/Models/`), 25 migrations, 11 factories. Directory path logic
lives on `Directory`; `DirectoryGrant`, `FileVersion`, `FileText`,
`SearchDocument`, `ArchivePeriod`, `DirectoryArchive`, `Property`,
`PropertyDefinition`, `Setting` are all modelled.

### 3. Access control — PASS

`app/Services/DirectoryAccess.php` is the single seam; four policies
(`Directory`, `File`, `PropertyDefinition`, `User`); Spatie roles are
created by the `doccum:ensure-roles` command, which the image runs on every
boot from `docker/entrypoint.d/51-doccum-roles.sh` — under the
create-what-is-absent rule, so a boot against a database an operator has
edited adds what is missing and deletes nothing (`decision/0073`). The first-run setup screen is `/setup` →
`App\Livewire\Setup\FirstRun`, middleware-guarded and unreachable once a user
exists.

Both authorisation layers are enforced independently. The test that proves
they are independent is `tests/Feature/FilePolicyTest.php:43`, "requires the
capability as well as the level": a user granted `Manage` on the directory,
whose role carries no `files.upload`, gets `false` from `can('create', …)`.
`tests/Feature/FileBrowserActionsTest.php:452` holds the same shape for the
replace path.

### 4. Storage — PASS

`Services\DocumentStorage` is the only code that resolves a disk. Upload,
versions, presigned download, trash and restore are all routed (see milestone
10's mechanical check) and all have UI destinations (`files.browse`,
`files.preview`, `files.download`, `files.versions.download`, `trash`).

### 5. Properties — PASS

`App\Livewire\Admin\PropertyDefinitions` is the definitions admin;
`app/Actions/Properties/SetProperties.php` writes typed values;
`PUT /api/v1/{directories,files}/{id}/properties` is the API half.

### 6. Extraction — PASS

`app/Extraction/ExtractorChain.php` with five strategies — `PlainTextExtractor`,
`PdfExtractor`, `OfficeXmlExtractor`, `OcrExtractor`, `UnsupportedExtractor` —
behind `app/Jobs/ExtractText.php`, with `ExtractRetry` as the manual re-drive
and `App\Enums\ExtractionStatus` as the recorded outcome. Every strategy shells
out through `Support\ProcessRunner`, so no test requires `tesseract` or any
other binary.

### 7. Search — PASS

`app/Search/` holds the `SearchIndex` seam with `Fts5SearchIndex` (SQLite) and
`LikeSearchIndex` (elsewhere), `PropertyFilter`, `Terms`, `SearchHit`.
`Services\Search` is the only entry point and resolves the viewer's reach
itself, so no caller can forget to. `SearchIndexer` and
`Jobs\ReindexSearchDocument` maintain the projection.

### 8. Archive and purge — PASS

`ClosePeriods`, `PurgePeriod`, `PurgeExpired`, `SweepUploads` commands;
`Services\PeriodCloser` and `Services\PeriodPurger`; `App\Livewire\Admin\Periods`
is the admin screen. Purge is deliberately **not** reachable from the API (§11:
"Admin … stays UI-only, so no token can reach `purge`"), and the mechanical
check below confirms no admin endpoint is routed.

### 9. Admin surface — PASS

All five §10 Settings sections exist as named routes: `admin.users`,
`admin.roles`, `admin.properties`, `admin.periods`, `admin.settings`. My Account
is `profile.edit`, `security.edit`, `appearance.edit`, `api-tokens.edit`.

### 10. API — PASS, mechanically

The spec's §11 endpoint table and `routes/api.php` were compared by extracting
both and diffing the sets:

```
spec: 21  routed: 21
IN SPEC, NOT ROUTED: none
ROUTED, NOT IN SPEC: none
```

Exact parity in both directions — so no spec endpoint is unimplemented, and no
endpoint has been added that the spec does not describe.

A note on how that number was reached, because the first attempt got it wrong:
a regex anchored on `Route::method(` and `Route::middleware(...)->method(`
reported three spec endpoints as unrouted. All three were real and routed, in a
multi-line chained form the pattern did not admit. The pattern was checking the
shapes its author had thought of. Re-run form-independently — any `method('…',`
call anywhere in the file — it reports 21/21. The narrow version would have
produced three false findings with full confidence.

Abilities gate every group (`ability:directories:read`, `files:write`,
`files:delete`, `properties:write`, `search`, and so on), under `auth:sanctum`.
`tests/Feature/Api/OpenApiContractTest.php` asserts the committed
`docs/api/openapi.json` matches the live route set in both directions, and
includes a self-check that the undocumented-route arm actually fires.

### 11. Documentation — **FAIL, three gaps**

What is there: `docs/api/openapi.json` committed and drift-tested;
`docs/self-hosting/` covering every bullet §12 names — quick start,
configuration reference, storage, search, operations runbook, backup and
restore, upgrading, troubleshooting; `README.md`; `LICENSE` (MIT).

What is missing:

1. **`CONTRIBUTING.md` does not exist** (issue #321). §12 names it;
   milestone 11 names it; no backlog item covers it. Confirmed by `find`
   across the tree.
2. **`SECURITY.md` does not exist** (issue #322). Same three statements. The
   only match for
   `SECURITY*` in the repository is `.github/workflows/security.yml`, which is
   a workflow, not a disclosure policy. For an OSS project that stores other
   people's documents, a missing disclosure policy is the more serious of the
   two.
3. **The OpenAPI reference is not `dedoc/scramble`, and nothing records the
   substitution** (issue #323). §12 specifies a reference "generated by
   `dedoc/scramble` from route signatures, FormRequests, and API Resources …
   served interactively at `/docs/api` outside production". What shipped is
   `app/Support/OpenApi/OpenApiSpecBuilder.php`, hand-rolled; `dedoc/scramble`
   is absent from `composer.json`; and no route serves `/docs/api`.

   The substitution may well be the better call — the contract test gives the
   anti-drift property §12 was actually reaching for, and it gives it more
   directly than generation does. That is not the finding. The finding is that
   a spec sentence and the code disagree with **no decision node reconciling
   them**, which is the same defect `item/spec-reconcile` and
   `item/spec-section8-reconcile` were opened to fix elsewhere. A reader
   comparing spec to code today finds a contradiction and no ruling.

   Two sub-gaps follow from it and are separable: the unrecorded substitution,
   and the missing interactive `/docs/api` route, which no substitution
   decision has yet disposed of either way.

A fourth, smaller observation, recorded but not filed: §12 asks README for
"what it is, screenshot, three-line quick start". The first and third are
there; there is no screenshot. It needs a running instance to produce, so it
sits with the owner-blocked work rather than with the three above.

## Backlog

93 items.

| Release | Status | Count |
|---|---|---|
| `v1.0.0` | Completed | 70 |
| `v1.0.0` | Active | 3 |
| `v1.0.0` | Potential | 3 |
| `post-v1` | Potential | 17 |

**70 of 76 v1 items are Completed.** The six open ones:

| Order | Item | Status | Issue | What it waits on |
|---|---|---|---|---|
| 52 | `item/release-v0-1-0` | Potential | #32 | Owner: authorising a tag |
| 60 | `item/docs-site` | Active | #27 | Owner: enabling GitHub Pages |
| 61 | `item/image-size` | Active | #30 | Owner: a published image to measure |
| 62 | `item/readme-owner` | Potential | #33 | Owner: a published image to point at |
| 68 | `item/v1-audit` | Active | #34 | This PR discharges clause (a) |
| 69 | `item/tag-v1-0-0` | Potential | #35 | Everything above |

The 17 `post-v1` items are deferred by design (semantic search, OCR providers,
alternative search engines, legal holds, the Alpine image) and are not v1 gaps.

### What this means for the gap

The v1 gap is **six items**, and after this PR the honest count of items whose
*next action belongs to this loop* is **one** — `item/v1-audit`'s remaining
clauses — plus the three documentation gaps above — issues #321, #322 and #323, all
new work this audit found, none of it blocked on anybody.

Everything else in the gap terminates at a decision only the repository owner
can make: enable Pages, authorise a tag, publish an image. Those are named in
run/0021 and unchanged by this audit.

## The audit's remaining clauses

`item/v1-audit`'s `doneWhen` has three parts. This PR discharges the first.

- **(a) A checklist walking milestones 1 to 11 and the backlog** — this file,
  and the body of the pull request that carries it.
- **(b) pint clean, phpstan at or below baseline, smoke green** — not claimable
  from this environment, which has no `vendor/`. It is claimable from a `main`
  push run by check name: `pint`, `lint` (which runs phpstan against
  `phpstan-baseline.neon`: 35 message blocks, 37 findings) and `image` (which
  runs `.github/scripts/container-smoke.mjs`). A `main` run is what will be
  cited, not a PR run — a PR's checks ran against the PR's head, not against
  the `main` that merging it produced.
- **(c) A manual run of the published image through install, upload, share,
  search, trash and a purge dry run** — blocked, and not by this loop. There is
  no published image, because there is no tag, because authorising one is the
  owner's call (#32). Clause (c) cannot be attempted before that happens.

So the audit closes when the three documentation gaps are fixed, a `main` run
is cited for (b), and the owner unblocks (c).

## Corrections this audit makes to existing text

`CLAUDE.md` says phpstan has "~44 pre-existing findings". The baseline today
holds 35 message blocks summing to 37. The tilde covers a drift of one or two,
not of seven; the number has moved as findings were fixed and the sentence did
not follow. Worth correcting the next time `CLAUDE.md` is touched for another
reason — not worth a commit of its own, and deliberately not fixed here, so
this PR stays one item.

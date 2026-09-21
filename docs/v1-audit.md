# v1 audit

The audit `item/v1-audit` asks for: a walk of spec milestones 1 to 11 and of
the backlog, against the code rather than against memory.

This file is the record. It is written to be **falsifiable**: every tick cites
something a reader can run or open, and every gap names what is missing rather
than what is planned. Where a claim could not be checked here, it says so and
names where it is checked instead.

Audited at `518275a`, against
`docs/superpowers/specs/2026-09-15-doccum-design.md` §15.

**RE-AUDITED at `b8b5026`, 2026-09-21.** The first pass failed milestone 11 on
three gaps and filed them as issues #321, #322 and #323. All three have since
shipped and their issues are closed, so milestone 11 is re-walked below and now
passes. Clause (b) is discharged in the same pass, against a named `main` run
-- and the way this file described clause (b) turned out to be unrunnable as
written; see **The audit's remaining clauses**. Nothing in milestones 1 to 10
is re-stated here: they passed and no change since has touched what they
assert. Where this file now disagrees with itself, the later text says so
rather than overwriting the earlier.

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

### 11. Documentation — PASS (was FAIL; re-walked at `b8b5026`)

What is there: `docs/api/openapi.json` committed and drift-tested;
`docs/self-hosting/` covering every bullet §12 names — quick start,
configuration reference, storage, search, operations runbook, backup and
restore, upgrading, troubleshooting; `README.md`; `LICENSE` (MIT); and now the
three things the first pass found missing.

**The first pass's three gaps, each re-checked against the tree rather than
against the pull request that claimed to close it:**

1. **`CONTRIBUTING.md`** (issue #321) — **present**, 174 lines, at the
   repository root where §12 names it. It leads with Node 22 and the npm 11
   silent-lockfile-rewrite trap, because that is the failure a first-time
   contributor actually hits.
2. **`SECURITY.md`** (issue #322) — **present**, at the repository root. Its
   scope list is written from doccum's own threat model rather than from a
   template: bypass of either authorisation layer *independently*, reach leaks
   through response differences, presigned-URL problems, the first-run
   installer on a populated instance, and a token reaching `purge`.

   One caveat this audit is obliged to record, because it is this file's job to
   be falsifiable: the installer bullet shipped with a false sentence, saying
   `/setup` redirects a visitor to login on a populated instance when
   `RequireInstanceSetup` answers 404. Corrected in PR #335. The document
   existing is what milestone 11 asks for and that is satisfied; the accuracy
   of its contents is not something a milestone tick can carry, which is why it
   is named here instead of folded away.
3. **The Scramble substitution** (issue #323) — **recorded**, in
   `decision/0118`, and §12 plus milestone 11 amended to match. §12 now reads,
   at line 1071: *"This paragraph previously specified generation by
   `dedoc/scramble` and an interactive `/docs/api` route outside production.
   Neither shipped, and `decision/0118` records why both were retired rather
   than built."* Both sub-gaps are disposed of: the substitution is recorded,
   and `/docs/api` is explicitly **retired** rather than left open — `docs` is
   in `.dockerignore`, so the committed `openapi.json` never reaches the image
   and serving it at runtime is impossible without changing what ships.

**Evidence kind: Cited**, not Mechanical. Each of the three is a file or a
node read by path at `b8b5026`, not a comparison a script performed. A stronger
check is not available for "a document exists and says the right thing", and
saying so is cheaper than implying otherwise.

The fourth, smaller observation from the first pass **stands and is not
discharged**: §12 asks README for "what it is, screenshot, three-line quick
start". The first and third are there; there is still no screenshot. It needs a
running instance to produce, so it sits with the owner-blocked work in clause
(c) rather than with the three above. Recorded, still not filed — a screenshot
is a clause of that work, not a defect of its own.

## Backlog

**RE-COUNTED at `b8b5026`.** 98 items, up from 93: the first pass's own three
issues became items, and two more (`item/vocab-resolves`,
`item/about-version-defined`) were filed and built in the same stretch.

| Release | Status | Count |
|---|---|---|
| `v1.0.0` | Completed | 74 |
| `v1.0.0` | Active | 3 |
| `v1.0.0` | Potential | 4 |
| `post-v1` | Potential | 17 |

**74 of 81 v1 items are Completed.** The seven open ones:

| Order | Item | Status | Issue | What it waits on |
|---|---|---|---|---|
| 52 | `item/release-v0-1-0` | Potential | #32 | Owner: authorising a tag (#302 states the choice) |
| 60 | `item/docs-site` | Active | #27 | Owner: enabling GitHub Pages |
| 61 | `item/image-size` | Active | #30 | Owner: a tag, so `release` runs and prints the sizes |
| 62 | `item/readme-owner` | Potential | #33 | Owner: a published image to point at |
| 68 | `item/v1-audit` | Active | #34 | Clause (c) only, after this PR |
| 69 | `item/tag-v1-0-0` | Potential | #35 | Everything above |
| 80 | `item/about-version-defined` | Potential | #289 | Nothing — shipped in PR #334 |

**Row 80 is a bookkeeping lag, not open work.** Its `doneWhen` is satisfied on
`main` at `b8b5026`: `about.version` is defined in `docs/ledger/vocab.md`, the
false `0.1.0` is corrected to `null`, and `item/tag-v1-0-0`'s clause (4) is
restated. It reads Potential here only because an item's status moves in the
ledger pass that FOLLOWS its merge, and that pass has not run. The count reads
75 of 81 after it does. Said plainly rather than pre-counted, because a table
that reports the future is not a snapshot.

The 17 `post-v1` items are deferred by design (semantic search, OCR providers,
alternative search engines, legal holds, the Alpine image) and are not v1 gaps.

### What this means for the gap

The first pass put the gap at six items, of which one plus three fresh
documentation issues belonged to this loop. **All four of those are now done**
— #321, #322 and #323 shipped and are closed, and clauses (a) and (b) of the
audit itself are discharged.

So the honest count of v1 work whose next action belongs to this loop is
**zero**. Every remaining item terminates at a decision only the repository
owner can make:

- **Authorise a tag** (#302, options A/B/C). This is the load-bearing one: it
  unblocks `item/release-v0-1-0`, then `item/image-size`'s last clause, then
  `item/readme-owner`'s clause 2, then this audit's clause (c), then
  `item/tag-v1-0-0`.
- **Enable GitHub Pages** (#27) — Settings → Pages → Source: GitHub Actions,
  plus a `DOCS_SITE_PAGES_ENABLED` repository variable. `item/docs-site`'s
  `deploy` job is gated to skip rather than fail until both are set.

Two further owner questions are open and block nothing: whether `release`
should be gated on the boot jobs (#303), and enabling private vulnerability
reporting, which `SECURITY.md` names as the reporting channel.

## The audit's remaining clauses

`item/v1-audit`'s `doneWhen` has three parts. The first pass discharged (a);
this pass discharges (b).

- **(a) A checklist walking milestones 1 to 11 and the backlog** — this file,
  and the body of the pull request that carried it. Milestone 11 has since been
  re-walked above, because it failed the first time and its three gaps have
  shipped.

- **(b) pint clean, phpstan at or below baseline, smoke green** — **DISCHARGED**,
  against `main` run `tests` **621** at `b8b5026`
  (<https://github.com/turbophp/doccum/actions/runs/35617315841>), a push run on
  `main` rather than a pull-request run, per LOOP.md step 3.

  | What the clause asks | Where it is answered on that run |
  |---|---|
  | pint clean | job `lint`, step **"Pint (format check)"** (`composer lint:check`) |
  | phpstan at or below baseline | job `lint`, step **"PHPStan"** (`composer types:check`, against `phpstan-baseline.neon`) |
  | smoke green | job `image`, which builds the container and drives `.github/scripts/container-smoke.mjs` |

  **THIS FILE DESCRIBED CLAUSE (b) IN A WAY THAT COULD NOT BE EXECUTED, and the
  correction is the point of this entry.** The first pass wrote that (b) is
  "claimable from a `main` push run by check name: `pint`, `lint` … and
  `image`". There is no `pint` check on a `main` push. `pint` is a job of
  `.github/workflows/format.yml`, whose `on.push.branches` is `claude/**` and
  `ledger/**` and nothing else — so a reader following that instruction would
  have gone looking on a `main` run for a check that never appears there, and
  either concluded the run was incomplete or quietly counted a `pint` check
  belonging to some other ref that happened to point at the same commit. LOOP.md
  step 3 records that second failure happening already, on `a0cf122`.

  Pint *does* run on `main` — as a **step** of the `lint` job, not as a check of
  its own. So the clause is satisfiable; only the instruction for satisfying it
  was wrong. A plan that names the wrong check is the same defect class as a
  citation that names the wrong file (`decision/0080`), except that it fails
  later and more quietly: nothing rejects it until someone tries to follow it.

  Phpstan's baseline holds **35 message blocks summing to 37 findings**,
  counted at `b8b5026` with `grep -c 'message:'` and by summing `count:`.

- **(c) A manual run of the published image through install, upload, share,
  search, trash and a purge dry run** — **still blocked, and not by this loop.**
  There is no published image, because there is no tag, because authorising one
  is the owner's call (#32, and #302 states the three options). Clause (c)
  cannot be attempted before that happens. The missing README screenshot from
  milestone 11 belongs to this clause for the same reason.

So the audit closes when the owner authorises a tag and clause (c) is run
against the image it publishes. Nothing else remains.

## Corrections this audit makes to existing text

`CLAUDE.md` said phpstan has "~44 pre-existing findings". The baseline holds 35
message blocks summing to 37, re-counted at `b8b5026`. The tilde covers a drift
of one or two, not of seven; the number moved as findings were fixed and the
sentence did not follow.

**MADE, 2026-09-21, in this pass.** The first pass wrote that it was "worth
correcting the next time `CLAUDE.md` is touched for another reason — not worth a
commit of its own, and deliberately not fixed here, so this PR stays one item".
That reasoning was sound for one pass and stops being sound at two: naming a
correction and then not making it, across two pull requests of the same item, is
what `decision/0107` calls displacement. It is the same item, so it is not
scope creep; `CLAUDE.md` now reads 37 and cites the baseline file, so the next
reader can re-derive it instead of trusting a tilde.

A note on which number to write, since two were available. 35 is the count of
`message:` blocks and 37 is the sum of their `count:` keys — the first is how
many distinct findings are baselined, the second how many occurrences. What a
developer running `phpstan analyse` against no baseline would see is the
occurrence count, so `CLAUDE.md` states 37 and says what it is.

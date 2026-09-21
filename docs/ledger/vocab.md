# Ledger vocabulary

`docs/ledger/context.jsonld` declares `@vocab` as
`https://github.com/turbophp/doccum/tree/main/docs/ledger/vocab#`. Every term
context.jsonld defines without an explicit `@id` resolves against that base --
which, until this file existed, pointed at nothing. This is what those terms
resolve into.

Each entry says what the term means in this ledger, then cites the code that
actually holds it to that meaning. `app/Support/LedgerValidator.php` is
framework-free by design (see its own docblock) and is the one place these
rules are checked, for both the Pest test and the CI CLI wrapper.

## `Ledger`

The root document, `docs/ledger/ledger.jsonld`'s single node. Everything else
in the ledger -- items, pull requests, decisions, mutations -- is a list
hanging off it, and it points at the run files separately through
`latestRun`.

Enforced: `app/Support/LedgerValidator.php` -- the root node must declare
`@type: Ledger`, and its allowed and required keys are both closed to
version, dateModified, latestRun, about, items, pullRequests, decisions,
mutations (`ALLOWED_KEYS`/`REQUIRED_KEYS['Ledger']`).

## `Run`

One iteration of the hourly loop: one file under `docs/ledger/runs/`,
recording what it touched and what it merged to main.

Enforced: `app/Support/LedgerValidator.php` -- a run's `@id` must match
`run/\d{4}` (`RUN_ID_PATTERN`) and match the filename it lives in; run
numbers must be contiguous from 0000 with no gaps; only the newest run may
carry `outcome: active` (`runErrors()`).

## `PullRequest`

A GitHub pull request the loop opened, keyed by its GitHub URL, recording
which Action(s) it implements and how it merged.

Enforced: `app/Support/LedgerValidator.php` -- `@id` must match
`https://github.com/turbophp/doccum/pull/\d+` (`PULL_REQUEST_ID_PATTERN`) and
the trailing number must equal `identifier` (`idErrors()`); its merge fields
are cross-checked against `Run.merges` (`pullRequestErrors()`).

## `Decision`

A record of a judgement call the loop made, and why -- distinct from a Run's
own hour-by-hour narrative in its `description`.

Enforced: `app/Support/LedgerValidator.php` -- `@id` must match
`decision/\d{4}` (`DECISION_ID_PATTERN`); `supersedes`, when set, must name a
strictly lower-numbered Decision (`decisionSupersedesErrors()`).

## `Mutation`

The ledger node for one mutation-testing check: a guard was deliberately
removed, a test was watched to see if it failed, and the guard was restored.
The node records what broke, what was supposed to catch it, and whether it
did.

Enforced: `app/Support/LedgerValidator.php` -- `@id` must match
`mutation/\d{4}` (`MUTATION_ID_PATTERN`); a Completed item based on spec:10,
once its own run is past run 18 (`MUTATION_RULES_EFFECTIVE_AFTER_RUN`), needs
at least one negative Mutation with a non-empty `check` implementing it, and
an un-superseded positive or void Mutation implementing an item blocks that
item's Completion (`actionStatusErrors()`).

## `items`

The Ledger's list of backlog Actions.

Enforced: `app/Support/LedgerValidator.php` -- required on `Ledger`; each
element must carry a valid `Action` `@type` with the closed key set
(`collectAndCheckStructure()`); the list must be sorted by `order` ascending
with no repeated `order` value (`orderingErrors()`).

## `pullRequests`

The Ledger's list of `PullRequest` nodes.

Enforced: `app/Support/LedgerValidator.php` -- required on `Ledger`; the list
must be sorted by `identifier` ascending (`orderingErrors()`).

## `decisions`

The Ledger's list of `Decision` nodes.

Enforced: `app/Support/LedgerValidator.php` -- required on `Ledger`; the list
must be sorted by `@id` ascending (`orderingErrors()`).

## `mutations`

The Ledger's list of `Mutation` nodes.

Enforced: `app/Support/LedgerValidator.php` -- required on `Ledger`; every
entry is checked by `mutationErrors()` (non-empty `implements`,
`check`/`verdict` coherence, `supersedes` numbering).

## `latestRun`

A pointer at the highest-numbered run file -- the one the loop is on, or has
just closed.

Enforced: `app/Support/LedgerValidator.php` -- must resolve to an existing
`Run` node (`referenceErrors()`); must actually be the highest-numbered run
file present, and `Ledger.dateModified` may not be earlier than that run's
`endTime` (`runErrors()`).

## `order`

An Action's position in the backlog. LOOP.md step 4 breaks ties in what the
loop picks next toward whatever unblocks the most other items, but `order`
itself is just a fixed rank.

Enforced: `app/Support/LedgerValidator.php` -- must be a positive integer
(context.jsonld's `xsd:integer`, `checkIntegerShape()`); must be unique
across items, and items must appear sorted by `order` ascending
(`orderingErrors()`).

## `size`

An Action's rough sizing.

Enforced: `app/Support/LedgerValidator.php` -- must be one of S, M, L
(`SIZES`, `checkAction()`).

## `release`

Which version an Action is scoped to.

Enforced: `app/Support/LedgerValidator.php` -- must match `vX.Y.Z`
(`RELEASE_PATTERN`) or equal the literal `post-v1` (`checkAction()`);
`item/tag-v1-0-0` cannot be marked Completed while any item whose `release`
is `v1.0.0` is not (`actionStatusErrors()`) -- this is the mechanism LOOP.md
names as the loop's actual stopping condition.

## `doneWhen`

The Action's own brief. LOOP.md step 5 calls this the only thing that binds
a worker to a scope: a Decision can decide work belongs to an item, only
`doneWhen` can require it (decision/0083).

Enforced: `app/Support/LedgerValidator.php` -- required on every `Action`
(`REQUIRED_KEYS`). Its content is free text; nothing here parses or checks
what it says.

## `dependsOn`

The set of Actions that must be Completed before this one can start.

Enforced: `app/Support/LedgerValidator.php` -- must be an array of strings
(`checkIdSetShape()`), each resolving to an existing `Action`
(`referenceErrors()`); the whole `dependsOn` graph must be acyclic
(`acyclicErrors()`); a Completed Action's `dependsOn` must themselves all be
Completed (`actionStatusErrors()`).

## `touched`

The set of Actions a Run's window actually worked on.

Enforced: `app/Support/LedgerValidator.php` -- must be an array of strings,
each resolving to an existing `Action` (`referenceErrors()`); a run other
than run/0000 with `outcome: completed` may not have an empty `touched`
(`runErrors()`) -- a run that did nothing must be distinguishable from one
that did.

## `outcome`

How a Run closed.

Enforced: `app/Support/LedgerValidator.php` -- must be one of completed,
failed, aborted, active (`RUN_OUTCOMES`); only the newest run may carry
`active`, so an unfinished record cannot be left behind (`runErrors()`); a
completed run's last merge must have closed both workflows green, for runs
past run 8 (`MERGES_OUTCOME_RULE_EFFECTIVE_AFTER_RUN`).

## `commit`

The Run's closing commit.

Enforced: `app/Support/LedgerValidator.php` -- must be a 40-character hex
sha (`SHA_PATTERN`); when `merges` is non-empty, must equal that list's last
entry's own `mergeSha` (`runErrors()`) -- there is no other stated meaning
for the field, and in practice it once drifted from that.

## `merges`

The ordered list of commits a Run's window actually merged to main, each
entry recording its sha, the PullRequest it came from (or `null` for a
ledger-only merge), and both workflows' conclusions.

Enforced: `app/Support/LedgerValidator.php` -- required on every `Run`, even
an empty list, so a run that merged nothing is distinguishable from one
nobody recorded (`REQUIRED_KEYS`); each entry is checked by
`checkMergeEntry()`; the CLI-supplied git history (`mainPushHistoryErrors()`)
requires every first-parent commit on main, up to the newest one the ledger
already knows about, to appear in some run's `merges`.

## `implements`

Which Action(s) a `PullRequest` or `Mutation` node discharges.

Enforced: `app/Support/LedgerValidator.php` -- must be a non-empty array of
strings, each resolving to an existing `Action` (`pullRequestErrors()`,
`mutationErrors()`, `referenceErrors()`); on a `PullRequest`, every id in
`implements` must also be in its own run's `touched` set.

## `affects`

Which Action(s) a `Decision`'s reasoning bears on.

Enforced: `app/Support/LedgerValidator.php` -- required key on every
`Decision` (`REQUIRED_KEYS`); each element, when present, must resolve to an
existing `Action` (`referenceErrors()`). No rule requires the list itself to
be non-empty.

## `supersedes`

A pointer at the earlier node -- a `Decision` or a `Mutation` -- that this
one replaces.

Enforced: `app/Support/LedgerValidator.php` -- must be a single string or
`null`, never an array (`checkIdSingleShape()` exists specifically because
decision/0030 once set it to `["decision/0026"]`); must resolve to a node of
the same type (`referenceErrors()`); must name a strictly lower-numbered node
of that type (`decisionSupersedesErrors()`, `mutationErrors()`).

## `run`

A pointer from a `PullRequest`, `Decision` or `Mutation` at the `Run` it
belongs to.

Enforced: `app/Support/LedgerValidator.php` -- must resolve to an existing
`Run` node (`referenceErrors()`); a `Decision`'s or `PullRequest`'s own
`dateCreated` must fall inside that run's `[startTime, endTime]` window
(`withinRunWindow()`).

## `mergedIn`

The `Run` a merged `PullRequest` actually landed in -- distinct from `run`,
which names where the PR was opened.

Enforced: `app/Support/LedgerValidator.php` -- must resolve to an existing
`Run` (`referenceErrors()`); must not be numbered earlier than the PR's own
`run` (`pullRequestErrors()`); the PR's `mergeSha` must appear in that run's
`merges[].mergeSha`.

## `state`

A `PullRequest`'s lifecycle state.

Enforced: `app/Support/LedgerValidator.php` -- must be one of open, merged,
closed (`PULL_REQUEST_STATES`); `state: merged` requires `mergedAt`,
`mergeSha` and `mergedIn` to all be set, and any other state requires all
three to be unset (`pullRequestErrors()`).

## `mergedAt`

When a `PullRequest` merged.

Enforced: `app/Support/LedgerValidator.php` -- must be a parseable
`xsd:dateTime` string or null (`checkDateTimeShape()`); required non-null
exactly when `state` is merged, and required null otherwise
(`pullRequestErrors()`).

## `headSha`

A `PullRequest`'s branch-tip commit.

Enforced: `app/Support/LedgerValidator.php` -- when present, must be a
40-character hex sha (`SHA_PATTERN`, `checkPullRequest()`); not a required
key.

## `mergeSha`

The commit a `PullRequest`'s merge, or a run's merge entry, actually
produced.

Enforced: `app/Support/LedgerValidator.php` -- must be a 40-character hex
sha; on a `PullRequest`, required exactly when `state` is merged
(`pullRequestErrors()`), and it must then appear in its own `mergedIn` run's
`merges[].mergeSha`; on a merge entry, always required (`checkMergeEntry()`).

## `pullRequest`

A pointer at a `PullRequest` node -- used on `Mutation` (the PR the check ran
in) and on a Run's merge entries (the PR that produced that commit, or
`null` for a ledger-only merge).

Enforced: `app/Support/LedgerValidator.php` -- on a merge entry, must be a
string or null (`checkMergeEntry()`); when present anywhere, must resolve to
an existing `PullRequest` node (`referenceErrors()`).

## `rationale`

A `Decision`'s own argument for why it was made.

Enforced: `app/Support/LedgerValidator.php` -- required on every `Decision`
(`REQUIRED_KEYS`). Its content is free text; nothing here checks what it
says.

## `mutant`

What a `Mutation` node broke -- names the guard, not what caught it.

Enforced: `app/Support/LedgerValidator.php` -- required on every `Mutation`
(`REQUIRED_KEYS`). Its content is free text; nothing here checks what it
says.

## `check`

The assertion the mutant was supposed to trip.

Enforced: `app/Support/LedgerValidator.php` -- the key is always present
(nullable, so a fresh node and a populated one share a shape); required to
be a non-empty string exactly when `verdict` is negative -- "a failure that
names no assertion is not evidence" -- and when `verdict` is not negative it
must be a string or null (`mutationErrors()`).

## `verdict`

Whether breaking the mutant actually made a test fail.

Enforced: `app/Support/LedgerValidator.php` -- must be one of negative,
positive, void (`MUTATION_VERDICTS`, `checkMutation()`); a positive or void
verdict on a Mutation that implements an item blocks that item's Completion
until a later Mutation supersedes it (`actionStatusErrors()`).

## `testsRun`

The GitHub Actions run URL for the `tests` workflow on one merge entry.

Enforced: `app/Support/LedgerValidator.php` -- must match a turbophp/doccum
Actions run URL (`WORKFLOW_RUN_URL_PATTERN`, `checkMergeEntry()`); if that
entry's `testsConclusion` is non-null, `testsRun` may not be null
(`runErrors()` rule 4) -- a conclusion with no run to point at is
incoherent.

## `testsConclusion`

The `tests` workflow's conclusion on one merge entry.

Enforced: `app/Support/LedgerValidator.php` -- must be one of GitHub's own
workflow-run conclusions (`WORKFLOW_CONCLUSIONS`, `checkMergeEntry()`); a
completed run's last merge entry must have this equal to `success`, for runs
past run 8 (`runErrors()` rule 3, `MERGES_OUTCOME_RULE_EFFECTIVE_AFTER_RUN`).

## `ledgerRun`

The GitHub Actions run URL for the `ledger` workflow on one merge entry.

Enforced: `app/Support/LedgerValidator.php` -- must match a turbophp/doccum
Actions run URL; if that entry's `ledgerConclusion` is non-null, `ledgerRun`
may not be null (`runErrors()` rule 4).

## `ledgerConclusion`

The `ledger` workflow's conclusion on one merge entry.

Enforced: `app/Support/LedgerValidator.php` -- must be one of GitHub's own
workflow-run conclusions; a completed run's last merge entry must have this
equal to `success`, for runs past run 8 (`runErrors()` rule 3).

## version on the about node

`Ledger.about` is a `SoftwareApplication` node -- the software the ledger
describes, embedded directly rather than referenced (it is the one term
`shapeErrors()` exempts from the usual "must be a string @id" check, because
it legitimately carries a full node). context.jsonld maps `version` in
general to `schema:version`, and that is the mapping `about.version` uses:
there is no `about`-specific override. `LedgerValidator` requires `version`
to be present on it (`REQUIRED_KEYS['SoftwareApplication']`) but checks
nothing else about its value -- no pattern, no relationship to any tag or
build.

`about.version` is **not** the version the working tree would build right
now. That is a separate value, `config('doccum.version')` in
`config/doccum.php`, which reads `env('DOCCUM_VERSION', '0.1.0-dev')` --
falling back to the literal string `0.1.0-dev` when that environment
variable is unset. `DOCCUM_VERSION` is set exactly once, as a Docker build
arg, by `.github/workflows/release.yml`'s `image` job, from
`steps.meta.outputs.version` -- `docker/metadata-action`'s reading of the git
tag that triggered the run (`type=semver,pattern={{version}}`). Outside a
tagged release build, nothing sets `DOCCUM_VERSION`, so the tree builds
`0.1.0-dev`.

As of this writing, `about.version` in `docs/ledger/ledger.jsonld` reads
`0.1.0`, while `git tag` on this repository lists nothing: no tag has been
pushed, so `release.yml` has never run to completion, no image carrying a
`DOCCUM_VERSION` build arg has ever been pushed to `ghcr.io`, and no GitHub
Release exists. So under any reading that requires a release to have
actually happened -- released, published, or built from a real tag --
`about.version` is currently false. It is a number someone wrote into the
ledger, not a number any of those three processes produced.

Which of the three readings `about.version` is *supposed* to mean is not
decided here. `item/about-version-defined` (issue #289) is where that choice
belongs, and it names candidates rather than picking one:

- the version this ledger records as **released** -- whatever the loop has
  decided v1 (or the current milestone) has reached, independent of whether
  a tag exists yet;
- the version **published** to `ghcr.io` -- the tag of whatever image a
  self-hoster's bare `docker pull` would actually receive today;
- the version the tree **would build** -- `config('doccum.version')`, i.e.
  `DOCCUM_VERSION` if a release build set it, else `0.1.0-dev`.

These three agree once a release has fully landed and nothing has changed
since. They diverge precisely *during* a release, because a release is not
one atomic event: the tag is pushed, then `verify` runs the suite, then
`image` builds and pushes to `ghcr.io`, then `release` cuts the GitHub
Release -- and at any point before all of those finish, "released" can be
ahead of "published," and both can be ahead of what an untagged checkout of
`main` would build if built right now.

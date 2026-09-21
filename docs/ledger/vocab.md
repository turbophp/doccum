# Ledger vocabulary

`docs/ledger/context.jsonld` declares `@vocab` as
`https://github.com/turbophp/doccum/blob/main/docs/ledger/vocab.md#`. Every
term context.jsonld defines without an explicit `@id` resolves against that
base -- which, until this file existed, pointed at nothing. This is what those
terms resolve into. The IRI is quoted here from context.jsonld line 4 as it
stands. This paragraph previously quoted `tree/main/docs/ledger/vocab#`, which
context.jsonld really did carry until `item/vocab-resolves` repointed it at
this file (PR #332) -- so the quotation was right when written and went stale
in the commit that made the rest of it true. A document that restates a value
acquires that risk; this note is here so the next reader checks line 4 rather
than trusting the restatement.

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
ledger-only merge), and the conclusion of every workflow a push to main
triggers -- one `{workflow}Run` / `{workflow}Conclusion` pair each, currently
`tests`, `ledger` and `pages`.

The set is not maintained here. `LedgerValidator::MAIN_PUSH_WORKFLOWS` holds
it and `.github/scripts/assert-merge-record-covers-main-push.py` refuses
unless that list equals the workflows `.github/workflows/` actually triggers
on a main push. It said "both workflows" while `pages` had been running on
main for two days (issue #375), which is why the authority moved out of prose.

A pair may be `null`/`null`, and on 189 entries it is: those merges predate
`fa499f5`, the first-parent commit at which `pages.yml` existed, so there is
no run to point at. What a null cannot do is hide a red run -- a conclusion
with no run is its own error, and the keys are always present, so "not
recorded" and "did not run" stay distinguishable.

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
there is no `about`-specific override.

**`about.version` is the ledger's record of the latest git tag pushed to this
repository, written without its leading `v` -- not the version the working
tree would build (that is `config('doccum.version')`, `0.1.0-dev` outside a
release build) and not a prediction of the next tag, and `null` while no tag
exists at all.**

That is the reading `item/about-version-defined` (issue #289) chose, and the
rest of this section is why, what it costs, and who keeps it true.

### Why this reading and not the other two

The other two were already spoken for.

- **The version the tree would build** is `config('doccum.version')`, which
  reads `env('DOCCUM_VERSION', '0.1.0-dev')`. `DOCCUM_VERSION` is set exactly
  once, as a Docker build arg, by `.github/workflows/release.yml`'s `image`
  job, from `steps.meta.outputs.version`. A value that already has a home does
  not get a second one here: two readers of one upstream value are one source
  wearing two hats, and their agreement is not evidence of anything
  (`decision/0080`). So nothing compares `about.version` against
  `config('doccum.version')`, and nothing should start: they will read alike
  after a release has fully landed, and that likeness is a coincidence of
  timing rather than a check.
- **A prediction of the next tag** is not a fact about anything, so no process
  could ever contradict it. A field the repository cannot falsify is a field
  that cannot be wrong, which is the same as saying it records nothing.

The chosen reading, by contrast, was already presumed by the code. The
"Deliberately NOT checked here" list in `app/Support/LedgerValidator.php`
names `that about.version matches the latest published git tag` as a rule the
validator declines only because it would need git history or the network. A
rule can be *deliberately* not checked only if someone knew what checking it
would mean, so that line has assumed this reading since it was written.
Ratifying it leaves the line correct; overruling it would have made the line
wrong and required changing it in the same breath, per `decision/0106`.

### It may lag, and it may never lead

`about.version` is a RECORD of the latest tag, not a reader of it, and the
difference is the whole of its safety. The loop writes it in the ledger pass
that follows the tag's `release.yml` run concluding `success` -- so between
the tag landing and that pass, the field is one release behind. It is never
ahead.

That direction is deliberate. `item/tag-v1-0-0`'s `doneWhen` reads this field
-- "the ledger's about.version reads 1.0.0" -- and `docs/LOOP.md`'s stopping
condition is that item reaching `CompletedActionStatus`, so the loop's own
termination depends on this value at one remove. (`LOOP.md` does not name
`about.version` itself; grep it and the only hit is line 224, saying the
section previously named a release node that never existed. The dependency is
real and indirect, and saying "LOOP.md reads this field" would be the kind of
citation `decision/0080` exists to catch.) A field that can only lag cannot
fire a stopping rule early. A tag whose release run FAILED is the case that makes
this concrete: the tag exists, so a reader of `git tag` would say `1.0.0`
while no image and no GitHub Release do -- and this field, written only after
a successful run, still says what was last actually released. The lag is the
feature.

### Who sets it, and when

The loop, in the ledger pass after the release run concludes `success`. Not
the owner: `decision/0117` retracted the premise that a ledger schema change
is the owner's, and this is the same class of work as the three schema changes
loop items already made to `context.jsonld` on 2026-09-18. What remains the
owner's is the tag itself, and for a harder reason than convention:
`decision/0085` recorded that `git push` of a tag returns HTTP 403 from the
loop's environment, and `decision/0092` -- which narrowed that wall by finding
`workflow_dispatch` accepted where the tag push was refused -- left the tag
push itself still refused. `item/tag-v1-0-0`'s clause (4) asked exactly this question
and now carries this answer.

### The value was wrong, and is corrected here

`about.version` read `0.1.0` from the ledger's first commit until
`item/about-version-defined` shipped. `git tag` on this repository lists
nothing: no tag has ever been pushed, `release.yml` has never run to
completion, no image carrying a `DOCCUM_VERSION` build arg exists on
`ghcr.io`, and no GitHub Release exists. So `0.1.0` was false under the
reading chosen above, and false under the published-to-`ghcr.io` reading too;
it was a number somebody wrote into the ledger, not a number any release
process produced. It matched `config/doccum.php`'s `0.1.0-dev` only if you
ignored the suffix.

It is now `null`, which is this ledger's existing way of saying "the thing
this field records has not happened yet" -- the same explicit-null convention
`REQUIRED_KEYS` documents for a Potential Action's `startTime` and a void
`Mutation`'s `check`, so a field nobody has set is distinguishable from one
nobody declared. `null` rather than a string like `unreleased`, because a
string is a value and would eventually be parsed as one.

`LedgerValidator` requires `version` to be PRESENT on the `about` node
(`REQUIRED_KEYS['SoftwareApplication']`) and checks nothing about its value:
no pattern, no relationship to a tag. Everything above is therefore a
convention this document holds, not a rule the validator enforces -- which is
worth saying plainly rather than leaving a reader to infer enforcement from
the citation line every other entry here carries.

Enforced: `app/Support/LedgerValidator.php` -- presence only
(`REQUIRED_KEYS['SoftwareApplication']` via `shapeErrors()`); the value's
meaning is held by this document and by `item/tag-v1-0-0`'s `doneWhen`, and
the tag comparison is on the validator's own "Deliberately NOT checked here"
list because it needs git history the class stays blind to.

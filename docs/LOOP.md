# The development loop

doccum is being driven to a tagged `v1.0.0` by an autonomous hourly loop. This
file is the protocol that loop follows. It is normative: an iteration that
skips a step here is a bug in the run, not a shortcut.

## Roles

Three models, three jobs. The split is deliberate — the expensive judgement
calls and the bulk execution have different shapes.

| Model | Role | Does |
|---|---|---|
| **Fable** | Consultation | Architecture, scope, what v1 means, whether the ledger vocabulary still fits reality. Advisory only: never writes files. |
| **Opus** | Governance & orchestration | Owns the ledger, picks the next item, reviews and merges PRs, decides when to escalate to Fable, decides when v1 is done. |
| **Sonnet** | Task execution | Implements one backlog item at a time against its plan, writes tests, pushes the branch. |

## One iteration

Every hour, the loop wakes and runs these steps in order.

1. **Re-read state.** `docs/ledger/ledger.jsonld` is the source of truth for
   what is done, in flight and next. Re-read it rather than trusting memory:
   a previous iteration may have run in a different session.

   Then list the open issues whose URL no item carries, and dispose of each:
   an item, a line in an existing item's `doneWhen`, or closed with a reason.
   Issue #74 sat open for a day with no ledger node and no item referencing
   it, while Download was dead in the shipped product; nothing in this loop
   was looking, because the ledger only describes work that is already on it.
   Two runs later the same sweep found that `AuthenticateUser`'s docblock
   claimed username login was "tracked as its own item" when no item
   mentioned username at all. An issue nobody has disposed of is invisible
   until `item/v1-audit`, which sits one place before the tag with no slack
   to fix anything.
2. **Review and merge.** Look at every open PR this loop opened. CI is the
   gate — a PR is mergeable only when every required check is green. Review
   the diff, then merge to `main`. Record the merge in the ledger.
3. **Watch `main` after each merge.** A PR's checks ran against the PR's head,
   not against the `main` that merging it produced, so they cannot speak for
   `main`. Wait for the push run on the merge commit and read its conclusion.
   A red `main` is this iteration's first work, and the next iteration's,
   ahead of picking a new item.

   Only the **newest** `main` run speaks for `main`. Ask for workflow *runs*
   at the merge SHA and filter them to `main`, not for check runs on the SHA:

   ```
   GET /repos/turbophp/doccum/actions/runs?head_sha=<merge sha>
   keep head_branch == "main" and event == "push"
   require the `tests` and `ledger` runs to both conclude "success"
   ```

   Those two are the whole of what a `main` push triggers — `format` is
   `claude/**` and `ledger/**` only, `phpstan-baseline` is `baseline/**`,
   `security` is pull requests and a weekly cron, `release` is tags. A run's
   own conclusion already aggregates its jobs, so this needs no count of
   checks, and a cancelled run reports `cancelled` rather than looking green.

   Counting check runs on the SHA instead gets both directions wrong, and
   both have happened. **Too few:** merging two PRs minutes apart lets the
   concurrency group cancel the first merge commit's run — `ba799a9` carries
   two green check runs and nine cancelled ones, so "are this commit's checks
   all green?" answers yes about a commit nothing verified. **Too many:**
   check runs attach to a SHA, not to a ref, so any branch pointing at the
   same commit contributes its own workflows. `a0cf122` read as twelve checks
   where `58399dc` read as eleven, and the twelfth was `pint` from a
   `claude/**` push of a branch that had been reset onto `main` — a workflow
   that never runs on `main` at all. A threshold like "at least eleven" can
   therefore be satisfied entirely by another ref's checks while `main`'s own
   `tests` run is still going.
4. **Pick the next item.** The first backlog item whose dependencies are all
   `Completed`. Ties break toward whatever unblocks the most other items.
5. **Execute.** Hand the item to a Sonnet worker with its plan, the relevant
   spec section and `CLAUDE.md`. One item per branch, one branch per PR.
6. **Verify through CI.** Push, open the PR, let the workflows run. Local
   runs are a convenience; the CI matrix is the verification of record,
   because it covers SQLite, PostgreSQL and MySQL and it boots the container.
7. **Update the ledger.** Append the run, move the item's status, record any
   decision taken and why.
8. **Consult.** Every fourth iteration, or whenever the backlog shape changes,
   ask Fable whether the remaining road to v1 and the ledger vocabulary still
   describe reality. Fold the answer back into the ledger.
9. **Re-arm.** Schedule the next wake-up. The loop stops only when the
   ledger's release node reaches `Completed` — that is, `v1.0.0` is tagged and
   the image is published.

## Rules the loop does not get to bend

- **CI is the gate.** Nothing merges red. "Flaky" is a diagnosis that has to
  be earned, not asserted; see `CLAUDE.md`.
- **"`main` is green" is a claim about `main`.** It may only be made after
  reading a `main` run, never inferred from the PR that merged into it. This
  rule exists because it was broken: by run 0012, eight of `main`'s
  thirty-eight `tests.yml` runs had failed — five of them after CI was
  established, every one on a merge commit whose PR had been fully green —
  while the loop reported `main` green each iteration, because the merge step
  checked the PR and nothing afterwards ever looked again. It is a claim about
  `main`'s *ref*, too, not about its commit: a SHA's check runs are whatever
  refs happen to point there, so the read is by workflow run filtered to
  `main` (step 3), never by counting checks on the SHA.
- **A green suite is necessary and not sufficient.** The `image` job exists
  because several bugs in this codebase were invisible to a fully green suite.
- **Never skip, disable or quarantine a test** to get a PR green.
- **Every guard gets a mutation check.** Delete the guard, watch the test
  fail, restore it — and say in the PR that you did.
- **Scope stays small.** One backlog item per PR. An item that grows past its
  "done when" line gets split in the ledger, not widened in the branch.
- **The ledger is append-only for history.** Runs and decisions are never
  rewritten; only item status and the release node are mutable.

## Stopping

The loop is finished when the ledger's `v1.0.0` release node is `Completed`:
every `required` backlog item done, the suite green across the full CI matrix,
the container smoke test passing, the tag pushed and `ghcr.io` carrying the
image. At that point the loop unschedules itself and reports.

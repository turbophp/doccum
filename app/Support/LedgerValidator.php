<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Structural and graph validation for docs/ledger/*.jsonld.
 *
 * Framework-free by design, matching ObjectKey: no Laravel classes, no I/O
 * beyond plain `file_get_contents`/`json_decode`. That lets it be loaded by
 * both a Pest test (tests/Feature/Ledger/LedgerIntegrityTest.php) and a
 * dependency-free CLI wrapper (.github/scripts/validate-ledger.php) so CI can
 * validate the ledger before `composer install` ever runs. One set of rules,
 * two callers -- the seam CLAUDE.md asks every capability to have.
 *
 * `docs/` is dockerignored (see CLAUDE.md), so this validator's own contract
 * is "operate only on files that may not exist" -- callers decide what a
 * missing ledger means (the test skips, the CLI fails loudly).
 *
 * Every method returns a list<string> of human-readable failures, each
 * prefixed with a category tag -- self::CODES is the real, checked
 * declaration of that set. This sentence used to be the only place it was
 * written down, as prose, and it was already wrong: it never mentioned
 * "shape:", added by issue #188, so it drifted the moment that rule shipped
 * and nothing noticed. item/ledger-rule-witnesses (issue #189) is what
 * turned it into self::CODES -- a constant a harness can assert against --
 * and tests/Feature/Ledger/RuleWitnessesTest.php re-derives the real set
 * from this file's own source so the two cannot silently disagree again.
 * An empty list from any one check below means that invariant holds.
 *
 * Deliberately NOT checked here (needs git history or the network, which the
 * design consultation excluded from "the files alone"):
 *   - that a PR's headSha/mergeSha actually exist as commits in this repo;
 *   - that a merged PR's run number matches when GitHub says it was merged;
 *   - that `about.version` matches the latest published git tag;
 *   - GitHub's own heading-anchor de-duplication (an "-1", "-2" suffix on a
 *     repeated heading slug) is not reproduced; the spec file has no
 *     duplicate headings today, so this cannot currently produce a false
 *     positive or negative.
 * These are named again where relevant below.
 */
final class LedgerValidator
{
    /**
     * The complete, closed set of category tags any error this class
     * returns is ever prefixed with. Sorted, so a caller can compare it
     * against a derived set without also having to sort that set the same
     * way first.
     *
     * This is a code PER CATEGORY, not per emission site: there are 82
     * `$errors[] = ` call sites behind these 16 tags (several `enum:` and
     * `structural:` sites alone), and App\Support\LedgerRuleWitnesses's own
     * docblock records why item/ledger-rule-witnesses (issue #189) witnesses
     * at this grain rather than one distinct code per site, and what full
     * site-level coverage would have needed instead.
     *
     * @var list<string>
     */
    public const CODES = [
        'canonical', 'decision', 'enum', 'fatal', 'graph', 'history', 'id',
        'mutation', 'order', 'pr', 'ref', 'run', 'shape', 'spec-anchor',
        'status', 'structural',
    ];

    /** JSON-LD keywords and CURIE namespace prefixes in context.jsonld -- infrastructure, not data terms. */
    private const CONTEXT_INFRASTRUCTURE_KEYS = ['@version', '@vocab', '@base', 'schema', 'xsd', 'spec'];

    /**
     * Terms context.jsonld types as a single @id reference where the seed
     * legitimately embeds a full node instead of pointing at one by string --
     * ordinary JSON-LD node embedding (Ledger.about holds a full
     * SoftwareApplication node, already validated structurally by
     * collectAndCheckStructure()), not a shape violation. shapeErrors()
     * skips these rather than reporting the embedded node as "not a string".
     */
    private const SHAPE_EXEMPT_TERMS = ['about'];

    private const ITEM_ID_PATTERN = '/^item\/[a-z0-9]+(-[a-z0-9]+)*$/';

    private const RUN_ID_PATTERN = '/^run\/\d{4}$/';

    private const DECISION_ID_PATTERN = '/^decision\/\d{4}$/';

    private const MUTATION_ID_PATTERN = '/^mutation\/\d{4}$/';

    private const PULL_REQUEST_ID_PATTERN = '#^https://github\.com/turbophp/doccum/pull/\d+$#';

    /**
     * Was MAIN_RUN_URL_PATTERN, back when a run URL lived on PullRequest as
     * mainRunUrl. item/ledger-main-push-record (issue #172) moved that fact
     * onto Run.merges as two named fields (testsRun, ledgerRun) instead --
     * the pattern itself is unchanged, just no longer "main"-specific since
     * there are now two named workflows rather than one undifferentiated one.
     */
    private const WORKFLOW_RUN_URL_PATTERN = '#^https://github\.com/turbophp/doccum/actions/runs/\d+$#';

    private const SHA_PATTERN = '/^[0-9a-f]{40}$/';

    private const RELEASE_PATTERN = '/^v\d+\.\d+\.\d+$/';

    private const SIZES = ['S', 'M', 'L'];

    private const ACTION_STATUSES = [
        'PotentialActionStatus', 'ActiveActionStatus', 'CompletedActionStatus', 'FailedActionStatus',
    ];

    private const PULL_REQUEST_STATES = ['open', 'merged', 'closed'];

    private const MUTATION_VERDICTS = ['negative', 'positive', 'void'];

    /**
     * GitHub's own workflow-run conclusions, per the Actions API. Not every
     * value is reachable through this repo's config (e.g. no run here uses
     * `action_required`), but the field is copied from GitHub's answer
     * rather than narrowed to what has been observed. `null` itself is not
     * listed here -- it is handled as "no value", the same as every other
     * nullable enum field in this class -- but it IS what a merge entry
     * carries for a workflow run that was cancelled before it concluded, or
     * that never existed for that push (see item/ledger-main-push-record,
     * issue #172, and Run.merges below).
     *
     * Was MAIN_CONCLUSIONS, back when this described PullRequest.mainConclusion
     * alone. Renamed for the same reason as WORKFLOW_RUN_URL_PATTERN.
     */
    private const WORKFLOW_CONCLUSIONS = [
        'success', 'failure', 'cancelled', 'skipped', 'timed_out', 'action_required', 'neutral', 'stale',
    ];

    /**
     * item/ledger-mutation-nodes (issue #150) added the Mutation vocabulary
     * and the rules below that lean on it. Every run up to and including
     * this one was recorded before that vocabulary existed -- some items
     * completed with an informal mutation check that never became a ledger
     * node (item/upload-silent-discard, run/0017), others completed with
     * none at all (item/topbar-shell, item/files-actions-ui,
     * item/download-reaches-the-browser, item/sqlite-immediate-transactions),
     * all in the same handful of runs, often the same run as an item that
     * *did* get one. There is no timestamp or run boundary that separates
     * "should have had a Mutation node" from "should not have" among that
     * history -- the practice was adopted per-item, ahead of being a rule.
     *
     * Rather than weaken the new rules to tolerate that inconsistency
     * forever, they simply do not look at runs at or before this one: a
     * Completed item is required to carry mutation evidence, and a merged
     * PullRequest is required to carry `mainConclusion`, only once its
     * own run number is strictly greater than this constant. Everything
     * already on the ledger is grandfathered by construction; the rule
     * binds going forward, from the run after the one that introduced it.
     */
    private const MUTATION_RULES_EFFECTIVE_AFTER_RUN = 18;

    /**
     * item/ledger-main-push-record (issue #172) added Run.merges and a rule
     * that a completed run's last merge must have closed both workflows
     * (tests, ledger) green -- otherwise the run cannot claim `completed`.
     *
     * That rule cannot be applied to history unconditionally: main has
     * actually gone red exactly twice, and in both cases the run that
     * contained the red merge nonetheless *closed* completed, because a
     * later merge in the same run's window fixed it before the run ended --
     * except once, run/0000 itself, whose very last merge before the loop
     * existed (2ef04dd5, "docs: track CLAUDE.md") landed on a red `tests`
     * run, and run/0008's last merge (38b4ba56, PR #63, schema-query-audit)
     * deliberately merged a red PostgreSQL leg -- see run/0009's own
     * description, which explains why fixing it there was not possible
     * without moving the fix to a dependent item. Both are documented,
     * intentional, already-closed history; rewriting `outcome` to make them
     * fit a rule invented after the fact is exactly the kind of fudging
     * CLAUDE.md's mutation-check discipline exists to prevent.
     *
     * So, the same shape as MUTATION_RULES_EFFECTIVE_AFTER_RUN above: the
     * rule binds only for a run numbered after this constant.
     *
     * The value is the LAST RUN THAT ACTUALLY VIOLATES IT (run/0008), not
     * the newest run in existence. Those are very different numbers and the
     * difference is the whole point: computed over the backfill, exactly two
     * runs close on a red merge -- run/0000 and run/0008 -- so a threshold of
     * 8 grandfathers precisely them and binds runs 9 onward, which is twelve
     * runs of real closed history this rule is now checked against. Setting
     * it to the newest run instead would exempt all twelve, and the rule
     * would read as enforcing twenty runs of history while enforcing none of
     * it: a guard that looks like protection, which is the thing this
     * codebase keeps having to dig out (decision/0062).
     */
    private const MERGES_OUTCOME_RULE_EFFECTIVE_AFTER_RUN = 8;

    /**
     * 'active' is the run currently being worked, and it exists because the
     * schema previously had no way to say so: a run had to claim it had
     * completed before it had, which is the one thing the ledger must never
     * make convenient. Only the latest run may be active -- enforced below --
     * so it cannot become a way to leave history unfinished.
     */
    private const RUN_OUTCOMES = ['completed', 'failed', 'aborted', 'active'];

    /**
     * Non-structural keys allowed per @type, beyond @id/@type/@context.
     *
     * context.jsonld is flat -- it defines terms, not which type each term
     * belongs on -- so this map is this validator's own design decision
     * about the closed vocabulary per type. It is deliberately a superset
     * of what the current seed uses (e.g. `description` on PullRequest,
     * `url`/`description` on SoftwareApplication) where the design
     * consultation's field list makes that a plausible future value,
     * documented here so it stays auditable in one place.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_KEYS = [
        'Ledger' => ['version', 'dateModified', 'latestRun', 'about', 'items', 'pullRequests', 'decisions', 'mutations'],
        'SoftwareApplication' => ['name', 'description', 'version', 'url'],
        // `url` is the item's tracking issue on GitHub. Optional rather than
        // required: the ledger is the state of record and has to stand on its
        // own if the issues are ever lost, renumbered or migrated.
        'Action' => [
            'order', 'name', 'url', 'description', 'isBasedOn', 'size', 'release', 'dependsOn',
            'doneWhen', 'actionStatus', 'startTime', 'endTime', 'result',
        ],
        // `tests`/`assertions` used to live here. item/ledger-field-audit
        // (issue #173) removed them: they were copied forward rather than
        // measured -- run/0016 and run/0017 both reported 649/1340 across a
        // run that merged five commits between them, and run/0019 and
        // run/0020 carried -1/-1 outright -- and the validator only ever
        // checked `int >= -1`, which every one of those values satisfies.
        // LedgerValidator is file-only and framework-free and runs in CI
        // BEFORE `composer install`, with no network, so it cannot itself
        // verify a real count; the Actions API exposes no structured test
        // count either, only a job log Pest's output would have to be
        // scraped from. So the only way to fill these was to hand-copy a
        // number from somewhere else -- which is indistinguishable from not
        // measuring at all, and worse than absent, because it reads as a
        // measurement. item/ledger-main-push-record (issue #172) already
        // gives anyone who wants the real count a way to get one: each
        // merge's `testsRun` URL on Run.merges points at the workflow run
        // that ran the suite.
        'Run' => [
            'identifier', 'agent', 'startTime', 'endTime', 'outcome', 'touched',
            'commit', 'description', 'merges',
        ],
        // `evidence` used to live here, as a Decision -> Mutation set link.
        // item/ledger-field-audit (issue #173) removed it: populated on 0 of
        // 66 decisions, so its referenceErrors() branch had never run
        // against real data. The relationship it names is real -- several
        // decisions (0038, 0040, 0062) rest their argument on a specific
        // Mutation's verdict and name it in `rationale` -- but there is no
        // rule connecting a Decision to a Mutation that does not also catch
        // decisions that merely SHARE a run and an affected item with an
        // unrelated one: computed over the backfill, "affects an item that a
        // Mutation in the same run also implements" matches 32 of 66
        // decisions, e.g. decision/0048 (a page nothing links to) and
        // decision/0052 (a dot in a wire:model path) alongside mutation/0015
        // purely because both touch item/admin-roles in run/0020, with no
        // evidentiary relationship at all. Even literal citation in prose is
        // not safe: decision/0047 names mutation/0014 only to date a sha, not
        // to rest an argument on its verdict. Enforcing either shape would
        // force a Decision to cite a Mutation it does not actually rely on --
        // exactly the "reads as evidence, proves nothing" defect this item
        // exists to remove, one level up. The one invariant actually worth
        // machine-checking here -- a Completed spec:10 item needs a negative
        // Mutation implementing it -- is already enforced directly against
        // Action/Mutation in actionStatusErrors(), without going through
        // Decision at all.
        'Decision' => ['dateCreated', 'run', 'name', 'description', 'rationale', 'isBasedOn', 'affects', 'supersedes'],
        // mainRunUrl/mainConclusion used to live here, describing main's
        // push run after a merge. item/ledger-main-push-record (issue #172)
        // moved that fact onto Run.merges instead: runErrors()'s own
        // enumeration iterates `pullRequests`, so it only ever saw merges
        // that carried a PullRequest node, and a ledger-only PR (no node at
        // all, by consistent practice rather than any rule) was invisible
        // to it -- both times main has actually gone red, it was exactly
        // this uncovered class. Run.merges enumerates the run's pushes
        // directly instead of walking PullRequest nodes, so it cannot
        // under-report the same way. See LedgerIntegrityTest and
        // MutationNodeRulesTest for the shipped and now-removed rule.
        'PullRequest' => [
            'identifier', 'name', 'description', 'dateCreated', 'state', 'headSha',
            'mergeSha', 'mergedAt', 'run', 'mergedIn', 'implements',
        ],
        // Sibling to PullRequest and Decision: item/ledger-mutation-nodes
        // (issue #150). `mutant` names what was broken; `check` names the
        // assertion that must trip. `check` is nullable -- void carries none,
        // because the run never reached one -- but the key is always present
        // (see REQUIRED_KEYS's docblock on mirroring defaults with explicit
        // null).
        'Mutation' => ['implements', 'pullRequest', 'run', 'headSha', 'mutant', 'check', 'verdict', 'supersedes'],
    ];

    /**
     * Keys every node of that @type must carry (value may be null where the
     * seed uses an explicit null -- e.g. a Potential Action's startTime --
     * so a fresh row and a populated one have the same shape; see CLAUDE.md's
     * "mirror database defaults" convention, applied here to JSON instead).
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_KEYS = [
        'Ledger' => ['version', 'dateModified', 'latestRun', 'about', 'items', 'pullRequests', 'decisions', 'mutations'],
        'SoftwareApplication' => ['name', 'version'],
        'Action' => [
            'order', 'name', 'isBasedOn', 'size', 'release', 'dependsOn',
            'doneWhen', 'actionStatus', 'startTime', 'endTime', 'result',
        ],
        // `merges` is required on every Run, including one with nothing
        // merged in its window (an empty list, e.g. run/0001 -- "Nothing is
        // merged, so no item is Completed", per its own description): the
        // key is always present so a run that merged nothing is
        // distinguishable from one nobody ever recorded, the same mirror-
        // the-default convention as Mutation.check below.
        'Run' => ['identifier', 'agent', 'startTime', 'endTime', 'outcome', 'touched', 'commit', 'description', 'merges'],
        'Decision' => ['dateCreated', 'run', 'name', 'rationale', 'isBasedOn', 'affects', 'supersedes'],
        'PullRequest' => ['identifier', 'name', 'dateCreated', 'state', 'run', 'implements'],
        'Mutation' => ['implements', 'pullRequest', 'run', 'headSha', 'mutant', 'check', 'verdict', 'supersedes'],
    ];

    /**
     * Run everything this class knows how to check.
     *
     * $mainPushShas, when given, is the list of first-parent commit shas on
     * main since the previous completed run's commit -- a git fact this
     * class stays deliberately blind to (see its own docblock and
     * item/ledger-main-push-record, issue #172): omission is detectable
     * only against an EXTERNAL list, so the CLI wrapper
     * (.github/scripts/validate-ledger.php) is the one caller that computes
     * it, from `git log --first-parent`, and hands it in. The other caller,
     * tests/Feature/Ledger/LedgerIntegrityTest.php, requires this file by
     * path with no git available at all and passes nothing -- so null (the
     * default) SKIPS this one check rather than failing it, and every other
     * rule in this class runs exactly as before.
     *
     * @param  ?list<string>  $mainPushShas
     * @return list<string>
     */
    public static function validate(string $ledgerDir, string $specPath, ?array $mainPushShas = null): array
    {
        $errors = [];

        $context = self::loadJson($errors, $ledgerDir.'/context.jsonld', 'context.jsonld');
        $ledgerRaw = self::readFile($errors, $ledgerDir.'/ledger.jsonld', 'ledger.jsonld');
        $runFiles = self::listRunFiles($errors, $ledgerDir.'/runs');

        // Nothing further can be checked meaningfully without these.
        if ($context === null || $ledgerRaw === null || $runFiles === null) {
            return $errors;
        }

        $ledger = json_decode($ledgerRaw, true);
        if (! is_array($ledger)) {
            $errors[] = 'fatal: ledger.jsonld is not a JSON object.';

            return $errors;
        }

        $runRaws = [];
        $runs = [];
        foreach ($runFiles as $filename => $path) {
            $raw = self::readFile($errors, $path, "runs/$filename");
            if ($raw === null) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $errors[] = "fatal: runs/$filename is not a JSON object.";

                continue;
            }
            $runRaws[$filename] = $raw;
            $runs[$filename] = $decoded;
        }

        if ($runRaws === []) {
            $errors[] = 'fatal: no run files were loaded; skipping checks that depend on them.';

            return $errors;
        }

        $vocabTerms = self::vocabTerms($context);

        [$nodes, $structuralErrors] = self::collectAndCheckStructure($ledger, $runs, $vocabTerms);
        $errors = array_merge($errors, $structuralErrors);

        // issue #188: closing the vocabulary (above) says which KEYS a node
        // may carry; this says what SHAPE each key's value must have, read
        // straight from context.jsonld's own @container/@type declarations,
        // rather than every other rule in this file silently skipping a
        // wrong-shaped value via array_filter(..., 'is_string') or crashing
        // on a (string) cast of an array. See shapeErrors()'s own docblock.
        $errors = array_merge($errors, self::shapeErrors($ledger, $runs, $context));

        $errors = array_merge($errors, self::idErrors($nodes));
        $errors = array_merge($errors, self::referenceErrors($ledger, $runs, $nodes));
        $errors = array_merge($errors, self::enumerationErrors($nodes));
        $errors = array_merge($errors, self::orderingErrors($ledger));
        $errors = array_merge($errors, self::canonicalSerializationErrors($ledgerRaw, $ledger, $runRaws, $runs));
        $errors = array_merge($errors, self::acyclicErrors($ledger));
        $errors = array_merge($errors, self::actionStatusErrors($ledger, $nodes));
        $errors = array_merge($errors, self::pullRequestErrors($ledger, $runs, $nodes));
        $errors = array_merge($errors, self::decisionSupersedesErrors($ledger));
        $errors = array_merge($errors, self::mutationErrors($ledger));
        $errors = array_merge($errors, self::runErrors($ledger, $runs));
        $errors = array_merge($errors, self::specAnchorErrors($ledger, $specPath));

        if ($mainPushShas !== null) {
            $errors = array_merge($errors, self::mainPushHistoryErrors($runs, $mainPushShas));
        }

        return $errors;
    }

    // -- Loading -------------------------------------------------------

    /** @param list<string> $errors */
    private static function readFile(array &$errors, string $path, string $label): ?string
    {
        if (! is_file($path)) {
            $errors[] = "fatal: $label is missing at $path.";

            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            $errors[] = "fatal: $label could not be read.";

            return null;
        }

        return $contents;
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, mixed>|null
     */
    private static function loadJson(array &$errors, string $path, string $label): ?array
    {
        $raw = self::readFile($errors, $path, $label);
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $errors[] = "fatal: $label is not valid JSON.";

            return null;
        }

        return $decoded;
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, string>|null map of filename (e.g. "0000.jsonld") to full path,
     *                                    sorted by filename.
     */
    private static function listRunFiles(array &$errors, string $runsDir): ?array
    {
        if (! is_dir($runsDir)) {
            $errors[] = "fatal: runs directory is missing at $runsDir.";

            return null;
        }

        $paths = glob($runsDir.'/*.jsonld');
        if ($paths === false || $paths === []) {
            $errors[] = "fatal: no *.jsonld files found under $runsDir.";

            return null;
        }

        sort($paths, SORT_STRING);

        $byFilename = [];
        foreach ($paths as $path) {
            $byFilename[basename($path)] = $path;
        }

        return $byFilename;
    }

    /**
     * The vocabulary terms context.jsonld defines for node properties and
     * types -- everything in its @context object except JSON-LD keywords
     * and the schema/xsd/spec CURIE prefixes, which name namespaces rather
     * than properties.
     *
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private static function vocabTerms(array $context): array
    {
        $entries = $context['@context'] ?? [];
        if (! is_array($entries)) {
            return [];
        }

        return array_values(array_diff(array_keys($entries), self::CONTEXT_INFRASTRUCTURE_KEYS));
    }

    // -- Shape from context ----------------------------------------------

    /**
     * issue #188: every other rule in this file learned to tolerate a
     * wrong-shaped value by silently skipping it (array_filter(...,
     * 'is_string'), is_string() guards before resolving a reference) --
     * useful so a bad value cannot cascade into unrelated failures or a
     * crash, but on its own that means a value context.jsonld describes as
     * one shape and the ledger gives another validates clean. Nothing was
     * actually reading the shape context.jsonld already declares.
     *
     * Four shapes are derived from context.jsonld's own term definitions,
     * rather than hand-listing which field is which:
     *   - idSet:      @type: @id with @container: @set or @list -- must be
     *                 an array, every element a string (dependsOn,
     *                 implements, affects, touched, isBasedOn, result).
     *   - idSingle:   @type: @id with no @container -- must be a string or
     *                 null (supersedes, run, mergedIn, latestRun,
     *                 pullRequest, testsRun, ledgerRun, url, agent -- and
     *                 `about`, but see SHAPE_EXEMPT_TERMS).
     *   - integer:    xsd:integer -- must be an int or null (order).
     *   - dateTime:   xsd:dateTime -- must be a parseable string or null
     *                 (dateCreated, dateModified, startTime, endTime,
     *                 mergedAt).
     *
     * A term with no @container and no @id/xsd @type (a plain string field
     * like `name` or `rationale`, or actionStatus's @type: @vocab) carries
     * no shape this method enforces -- there is nothing in context.jsonld to
     * derive a rule from.
     *
     * Deliberately not walked here: `items`/`pullRequests`/`decisions`/
     * `mutations`/`merges` themselves. Each is a @container of embedded
     * OBJECTS, not @id references (no `@type: @id` alongside their
     * `@container`), and each already has its own not-a-list/not-an-object
     * guard elsewhere (collectAndCheckStructure()'s per-collection loops,
     * checkRunMerges()) that does not crash on a wrong shape either.
     *
     * "Exactly one" error per wrong-shaped field, even when an idSet has
     * several bad elements: several errors from one bad value would be the
     * same cascading noise this rule exists to replace, just relocated to a
     * new tag.
     *
     * @param  array<string, mixed>  $ledger
     * @param  array<string, array<string, mixed>>  $runs
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private static function shapeErrors(array $ledger, array $runs, array $context): array
    {
        $errors = [];
        $shapes = self::termShapes($context);

        $checkFields = function (array $node, string $label) use (&$errors, $shapes): void {
            foreach ($node as $key => $value) {
                $shape = $shapes[$key] ?? null;
                if ($shape !== null) {
                    self::checkShape($errors, $label, $key, $shape, $value);
                }
            }
        };

        $checkFields($ledger, 'Ledger');

        foreach (['items' => 'Action', 'pullRequests' => 'PullRequest', 'decisions' => 'Decision', 'mutations' => 'Mutation'] as $collection => $typeLabel) {
            foreach (($ledger[$collection] ?? []) as $index => $node) {
                if (! is_array($node)) {
                    continue;
                }
                $id = is_string($node['@id'] ?? null) ? $node['@id'] : "$collection[$index]";
                $checkFields($node, "$typeLabel '$id'");
            }
        }

        foreach ($runs as $filename => $run) {
            $id = is_string($run['@id'] ?? null) ? $run['@id'] : $filename;
            $checkFields($run, "Run '$id'");

            foreach (($run['merges'] ?? []) as $index => $entry) {
                if (is_array($entry)) {
                    $checkFields($entry, "Run '$id'.merges[$index]");
                }
            }
        }

        return $errors;
    }

    /**
     * Reads the shape context.jsonld declares for each vocabulary term. See
     * shapeErrors()'s own docblock for what each returned shape means.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    private static function termShapes(array $context): array
    {
        $entries = $context['@context'] ?? [];
        if (! is_array($entries)) {
            return [];
        }

        $shapes = [];
        foreach ($entries as $term => $definition) {
            if (! is_string($term)
                || in_array($term, self::CONTEXT_INFRASTRUCTURE_KEYS, true)
                || in_array($term, self::SHAPE_EXEMPT_TERMS, true)
                || ! is_array($definition)) {
                continue;
            }

            $container = $definition['@container'] ?? null;
            $type = $definition['@type'] ?? null;

            if ($type === '@id' && in_array($container, ['@set', '@list'], true)) {
                $shapes[$term] = 'idSet';
            } elseif ($type === '@id') {
                $shapes[$term] = 'idSingle';
            } elseif ($type === 'xsd:integer') {
                $shapes[$term] = 'integer';
            } elseif ($type === 'xsd:dateTime') {
                $shapes[$term] = 'dateTime';
            }
        }

        return $shapes;
    }

    /** @param list<string> $errors */
    private static function checkShape(array &$errors, string $label, string $key, string $shape, mixed $value): void
    {
        match ($shape) {
            'idSet' => self::checkIdSetShape($errors, $label, $key, $value),
            'idSingle' => self::checkIdSingleShape($errors, $label, $key, $value),
            'integer' => self::checkIntegerShape($errors, $label, $key, $value),
            'dateTime' => self::checkDateTimeShape($errors, $label, $key, $value),
            default => null,
        };
    }

    /**
     * context.jsonld's @container: @set/@list -- must be an array, and every
     * element a string. Exactly one error even when several elements are
     * bad: this is what makes `dependsOn: ["item/x", 42]` one shape: error
     * rather than a per-element pile-on.
     *
     * @param  list<string>  $errors
     */
    private static function checkIdSetShape(array &$errors, string $label, string $key, mixed $value): void
    {
        if (! is_array($value)) {
            $errors[] = "shape: $label.$key = ".self::describe($value).' is not an array, but context.jsonld declares it @container: @set/@list.';

            return;
        }

        foreach ($value as $element) {
            if (! is_string($element)) {
                $errors[] = "shape: $label.$key contains a non-string element (".self::describe($element).'), but context.jsonld types every element as an @id reference.';

                return;
            }
        }
    }

    /**
     * context.jsonld's @type: @id with no @container -- must be a string or
     * null, never an array. This is decision/0030's own defect: `supersedes:
     * ["decision/0026"]` where context.jsonld declares a single @id.
     *
     * @param  list<string>  $errors
     */
    private static function checkIdSingleShape(array &$errors, string $label, string $key, mixed $value): void
    {
        if ($value === null || is_string($value)) {
            return;
        }

        $errors[] = "shape: $label.$key = ".self::describe($value).' is neither a string nor null, but context.jsonld declares it a single @id reference.';
    }

    /** @param list<string> $errors */
    private static function checkIntegerShape(array &$errors, string $label, string $key, mixed $value): void
    {
        if ($value === null || is_int($value)) {
            return;
        }

        $errors[] = "shape: $label.$key = ".self::describe($value).' is not an integer, but context.jsonld declares it xsd:integer.';
    }

    /** @param list<string> $errors */
    private static function checkDateTimeShape(array &$errors, string $label, string $key, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value) || strtotime($value) === false) {
            $errors[] = "shape: $label.$key = ".self::describe($value).' is not a parseable xsd:dateTime string, but context.jsonld declares it xsd:dateTime.';
        }
    }

    // -- Structure: @type, closed vocabulary, required keys ------------

    /**
     * Walks every node in the ledger and every run file, checking:
     *   - exactly one @type, from the known types (self::ALLOWED_KEYS's keys);
     *   - every non-JSON-LD key is a term context.jsonld defines AND is
     *     allowed on that type (self::ALLOWED_KEYS);
     *   - every required key for that type is present (self::REQUIRED_KEYS).
     *
     * @param  array<string, mixed>  $ledger
     * @param  array<string, array<string, mixed>>  $runs
     * @param  list<string>  $vocabTerms
     * @return array{0: array<string, array{type: string, node: array<string, mixed>}>, 1: list<string>}
     */
    private static function collectAndCheckStructure(array $ledger, array $runs, array $vocabTerms): array
    {
        $errors = [];
        $nodes = [];

        $check = function (string $id, array $node, string $context) use (&$errors, &$nodes, $vocabTerms): void {
            $type = $node['@type'] ?? null;

            if (! is_string($type) || ! array_key_exists($type, self::ALLOWED_KEYS)) {
                $errors[] = "structural: $context ($id) has no valid @type (found: ".self::describe($type).').';

                return;
            }

            if (isset($nodes[$id])) {
                // $nodes is keyed by @id, so a second node sharing it would
                // silently overwrite the first here and this duplicate would
                // never be visible again -- catch it now, one step before
                // that happens. This is why idErrors() used to carry a
                // "duplicate @id" check that could never fire: by the time
                // it ran, $nodes already had unique keys by construction.
                // JSON-LD merges nodes that share an @id, so two entries for
                // one id are not two items to any consumer -- they are one
                // item whose fields came from whichever copy parsed last.
                $errors[] = "id: '$id' is defined by more than one node -- JSON-LD merges nodes sharing an @id, so this is not two items to any consumer, it is one item whose fields came from whichever copy parsed last.";
            }

            $nodes[$id] = ['type' => $type, 'node' => $node];

            $structuralKeys = array_diff(array_keys($node), ['@id', '@type', '@context']);
            foreach ($structuralKeys as $key) {
                if (! in_array($key, $vocabTerms, true)) {
                    $errors[] = "structural: $context ($id) has key '$key', which context.jsonld does not define.";

                    continue;
                }
                if (! in_array($key, self::ALLOWED_KEYS[$type], true)) {
                    $errors[] = "structural: $context ($id) has key '$key', which is not allowed on a $type.";
                }
            }

            foreach (self::REQUIRED_KEYS[$type] as $required) {
                if (! array_key_exists($required, $node)) {
                    $errors[] = "structural: $context ($id) is missing required key '$required' for $type.";
                }
            }
        };

        if (($ledger['@type'] ?? null) === 'Ledger') {
            $check('ledger', $ledger, 'the ledger document');
        } else {
            $errors[] = 'structural: ledger.jsonld root has no @type of Ledger.';
        }

        if (isset($ledger['about']) && is_array($ledger['about'])) {
            $aboutId = $ledger['about']['@id'] ?? 'about';
            $check(is_string($aboutId) ? $aboutId : 'about', $ledger['about'], 'ledger.about');
        }

        foreach (($ledger['items'] ?? []) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = $item['@id'] ?? "items[$index]";
            $check(is_string($id) ? $id : "items[$index]", $item, 'ledger item');
        }

        foreach (($ledger['pullRequests'] ?? []) as $index => $pr) {
            if (! is_array($pr)) {
                continue;
            }
            $id = $pr['@id'] ?? "pullRequests[$index]";
            $check(is_string($id) ? $id : "pullRequests[$index]", $pr, 'ledger pull request');
        }

        foreach (($ledger['decisions'] ?? []) as $index => $decision) {
            if (! is_array($decision)) {
                continue;
            }
            $id = $decision['@id'] ?? "decisions[$index]";
            $check(is_string($id) ? $id : "decisions[$index]", $decision, 'ledger decision');
        }

        foreach (($ledger['mutations'] ?? []) as $index => $mutation) {
            if (! is_array($mutation)) {
                continue;
            }
            $id = $mutation['@id'] ?? "mutations[$index]";
            $check(is_string($id) ? $id : "mutations[$index]", $mutation, 'ledger mutation');
        }

        foreach ($runs as $filename => $run) {
            $id = $run['@id'] ?? $filename;
            $check(is_string($id) ? $id : $filename, $run, "run file $filename");
        }

        return [$nodes, $errors];
    }

    private static function describe(mixed $value): string
    {
        return is_string($value) ? "'$value'" : gettype($value);
    }

    /**
     * Coerces a context @set/@list value (dependsOn, implements, affects,
     * touched, isBasedOn, result) to the list<string> every rule but
     * shapeErrors() actually wants, tolerating a shape shapeErrors() has
     * already reported -- a bare scalar instead of an array, or an array
     * containing something other than a string -- by treating it as empty
     * rather than crashing.
     *
     * Before issue #188, every call site did this inline as
     * `array_filter($x ?? [], 'is_string')`, which throws a TypeError the
     * moment $x is not an array at all (PullRequest.implements given as a
     * bare string, rather than `["item/x"]`, crashed exactly this way in
     * referenceErrors()). Centralising it here means the "tolerate, don't
     * crash" behaviour lives in one place, and shapeErrors() is the one
     * place that actually reports the wrong shape as an error.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    // -- @id patterns and uniqueness ------------------------------------

    /**
     * @param  array<string, array{type: string, node: array<string, mixed>}>  $nodes
     * @return list<string>
     */
    private static function idErrors(array $nodes): array
    {
        $errors = [];

        // Duplicate-@id detection does NOT belong here: $nodes is keyed by
        // @id, so its keys are unique by construction and a loop like this
        // one used to sit beside this comment doing nothing. The real check
        // lives in collectAndCheckStructure(), before $nodes[$id] is
        // assigned -- the one point where a second node sharing an @id is
        // still visible, before it overwrites the first.
        foreach ($nodes as $id => $entry) {
            $pattern = match ($entry['type']) {
                'Action' => self::ITEM_ID_PATTERN,
                'Run' => self::RUN_ID_PATTERN,
                'Decision' => self::DECISION_ID_PATTERN,
                'PullRequest' => self::PULL_REQUEST_ID_PATTERN,
                'Mutation' => self::MUTATION_ID_PATTERN,
                default => null,
            };

            if ($pattern !== null && ! preg_match($pattern, $id)) {
                $errors[] = "id: '$id' ({$entry['type']}) does not match its required pattern $pattern.";
            }

            if ($entry['type'] === 'PullRequest') {
                $expected = self::pullRequestNumber($id);
                $actual = $entry['node']['identifier'] ?? null;
                if ($expected !== null && $actual !== $expected) {
                    $errors[] = "id: PullRequest '$id' has identifier ".self::describe($actual)." but its @id ends in $expected.";
                }
            }
        }

        return $errors;
    }

    private static function pullRequestNumber(string $id): ?int
    {
        return preg_match('#/pull/(\d+)$#', $id, $m) === 1 ? (int) $m[1] : null;
    }

    private static function runNumber(string $id): ?int
    {
        return preg_match('#^run/(\d{4})$#', $id, $m) === 1 ? (int) $m[1] : null;
    }

    private static function decisionNumber(string $id): ?int
    {
        return preg_match('#^decision/(\d{4})$#', $id, $m) === 1 ? (int) $m[1] : null;
    }

    private static function mutationNumber(string $id): ?int
    {
        return preg_match('#^mutation/(\d{4})$#', $id, $m) === 1 ? (int) $m[1] : null;
    }

    // -- Reference resolution --------------------------------------------

    /**
     * dependsOn, implements, affects, touched, supersedes, run, mergedIn,
     * latestRun, and any result entry must each resolve to an existing node
     * of the expected type.
     *
     * @param  array<string, mixed>  $ledger
     * @param  array<string, array<string, mixed>>  $runs
     * @param  array<string, array{type: string, node: array<string, mixed>}>  $nodes
     * @return list<string>
     */
    private static function referenceErrors(array $ledger, array $runs, array $nodes): array
    {
        $errors = [];

        $resolve = function (?string $ref, string $expectedType, string $context) use (&$errors, $nodes): void {
            if ($ref === null) {
                return;
            }
            if (! isset($nodes[$ref])) {
                $errors[] = "ref: $context references '$ref', which does not exist.";

                return;
            }
            if ($nodes[$ref]['type'] !== $expectedType) {
                $errors[] = "ref: $context references '$ref', which is a {$nodes[$ref]['type']}, not a $expectedType.";
            }
        };

        // Untyped $refs deliberately: a @set/@list field is expected to be an
        // array of strings, but shapeErrors() is what reports it when it is
        // not -- this closure only resolves the strings that ARE there
        // (self::stringList() below tolerates anything else by returning
        // none), so a wrong-shaped value degrades to "nothing to resolve"
        // here rather than a TypeError from an `array $refs` parameter that
        // a bare string or an int cannot satisfy. See issue #188.
        $resolveEach = function (mixed $refs, string $expectedType, string $context) use ($resolve): void {
            foreach (self::stringList($refs) as $ref) {
                $resolve($ref, $expectedType, $context);
            }
        };

        foreach (($ledger['items'] ?? []) as $item) {
            if (! is_array($item) || ! is_string($item['@id'] ?? null)) {
                continue;
            }
            $id = $item['@id'];
            $resolveEach($item['dependsOn'] ?? [], 'Action', "Action '$id'.dependsOn");
            $resolveEach($item['result'] ?? [], 'PullRequest', "Action '$id'.result");
        }

        foreach (($ledger['pullRequests'] ?? []) as $pr) {
            if (! is_array($pr) || ! is_string($pr['@id'] ?? null)) {
                continue;
            }
            $id = $pr['@id'];
            $resolveEach($pr['implements'] ?? [], 'Action', "PullRequest '$id'.implements");
            if (isset($pr['run']) && is_string($pr['run'])) {
                $resolve($pr['run'], 'Run', "PullRequest '$id'.run");
            }
            if (isset($pr['mergedIn']) && is_string($pr['mergedIn'])) {
                $resolve($pr['mergedIn'], 'Run', "PullRequest '$id'.mergedIn");
            }
        }

        foreach (($ledger['decisions'] ?? []) as $decision) {
            if (! is_array($decision) || ! is_string($decision['@id'] ?? null)) {
                continue;
            }
            $id = $decision['@id'];
            $resolveEach($decision['affects'] ?? [], 'Action', "Decision '$id'.affects");
            if (isset($decision['run']) && is_string($decision['run'])) {
                $resolve($decision['run'], 'Run', "Decision '$id'.run");
            }
            if (isset($decision['supersedes']) && is_string($decision['supersedes'])) {
                $resolve($decision['supersedes'], 'Decision', "Decision '$id'.supersedes");
            }
        }

        foreach (($ledger['mutations'] ?? []) as $mutation) {
            if (! is_array($mutation) || ! is_string($mutation['@id'] ?? null)) {
                continue;
            }
            $id = $mutation['@id'];
            $resolveEach($mutation['implements'] ?? [], 'Action', "Mutation '$id'.implements");
            if (isset($mutation['pullRequest']) && is_string($mutation['pullRequest'])) {
                $resolve($mutation['pullRequest'], 'PullRequest', "Mutation '$id'.pullRequest");
            }
            if (isset($mutation['run']) && is_string($mutation['run'])) {
                $resolve($mutation['run'], 'Run', "Mutation '$id'.run");
            }
            if (isset($mutation['supersedes']) && is_string($mutation['supersedes'])) {
                $resolve($mutation['supersedes'], 'Mutation', "Mutation '$id'.supersedes");
            }
        }

        foreach ($runs as $filename => $run) {
            $id = is_string($run['@id'] ?? null) ? $run['@id'] : $filename;
            $resolveEach($run['touched'] ?? [], 'Action', "Run '$id'.touched");

            foreach (($run['merges'] ?? []) as $index => $entry) {
                if (is_array($entry) && is_string($entry['pullRequest'] ?? null)) {
                    $resolve($entry['pullRequest'], 'PullRequest', "Run '$id'.merges[$index].pullRequest");
                }
            }
        }

        if (isset($ledger['latestRun']) && is_string($ledger['latestRun'])) {
            $resolve($ledger['latestRun'], 'Run', 'Ledger.latestRun');
        }

        return $errors;
    }

    // -- Enumerations ------------------------------------------------------

    /**
     * @param  array<string, array{type: string, node: array<string, mixed>}>  $nodes
     * @return list<string>
     */
    private static function enumerationErrors(array $nodes): array
    {
        $errors = [];

        foreach ($nodes as $id => $entry) {
            $node = $entry['node'];

            match ($entry['type']) {
                'Action' => self::checkAction($id, $node, $errors),
                'PullRequest' => self::checkPullRequest($id, $node, $errors),
                'Run' => self::checkRun($id, $node, $errors),
                'Mutation' => self::checkMutation($id, $node, $errors),
                default => null,
            };
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $errors
     */
    private static function checkAction(string $id, array $node, array &$errors): void
    {
        if (isset($node['size']) && ! in_array($node['size'], self::SIZES, true)) {
            $errors[] = "enum: Action '$id'.size = ".self::describe($node['size']).' is not one of S, M, L.';
        }
        if (isset($node['release']) && $node['release'] !== 'post-v1' && ! preg_match(self::RELEASE_PATTERN, (string) $node['release'])) {
            $errors[] = "enum: Action '$id'.release = ".self::describe($node['release']).' is neither vX.Y.Z nor post-v1.';
        }
        if (isset($node['actionStatus']) && ! in_array($node['actionStatus'], self::ACTION_STATUSES, true)) {
            $errors[] = "enum: Action '$id'.actionStatus = ".self::describe($node['actionStatus']).' is not a known schema.org ActionStatus.';
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $errors
     */
    private static function checkPullRequest(string $id, array $node, array &$errors): void
    {
        if (isset($node['state']) && ! in_array($node['state'], self::PULL_REQUEST_STATES, true)) {
            $errors[] = "enum: PullRequest '$id'.state = ".self::describe($node['state']).' is not one of open, merged, closed.';
        }
        foreach (['headSha', 'mergeSha'] as $shaField) {
            $value = $node[$shaField] ?? null;
            if ($value !== null && ! preg_match(self::SHA_PATTERN, (string) $value)) {
                $errors[] = "enum: PullRequest '$id'.$shaField = ".self::describe($value).' is not a 40-character hex sha.';
            }
        }
        // mainConclusion/mainRunUrl used to be checked here; they moved to
        // Run.merges (item/ledger-main-push-record, issue #172) -- see
        // checkMergeEntry() and ALLOWED_KEYS['PullRequest']'s own comment.
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $errors
     */
    private static function checkRunMerges(string $runId, array $node, array &$errors): void
    {
        $merges = $node['merges'] ?? null;
        if (! is_array($merges)) {
            $errors[] = "enum: Run '$runId'.merges is not a list.";

            return;
        }

        foreach ($merges as $index => $entry) {
            self::checkMergeEntry($runId, (string) $index, $entry, $errors);
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private static function checkMergeEntry(string $runId, string $index, mixed $entry, array &$errors): void
    {
        if (! is_array($entry)) {
            $errors[] = "enum: Run '$runId'.merges[$index] is not an object.";

            return;
        }

        $mergeSha = $entry['mergeSha'] ?? null;
        if (! is_string($mergeSha) || ! preg_match(self::SHA_PATTERN, $mergeSha)) {
            $errors[] = "enum: Run '$runId'.merges[$index].mergeSha = ".self::describe($mergeSha).' is not a 40-character hex sha.';
        }

        $pullRequest = $entry['pullRequest'] ?? null;
        if ($pullRequest !== null && ! is_string($pullRequest)) {
            $errors[] = "enum: Run '$runId'.merges[$index].pullRequest = ".self::describe($pullRequest).' is neither a string nor null.';
        }

        foreach (['testsConclusion', 'ledgerConclusion'] as $field) {
            $value = $entry[$field] ?? null;
            if ($value !== null && ! in_array($value, self::WORKFLOW_CONCLUSIONS, true)) {
                $errors[] = "enum: Run '$runId'.merges[$index].$field = ".self::describe($value).' is not a known GitHub workflow-run conclusion.';
            }
        }

        foreach (['testsRun', 'ledgerRun'] as $field) {
            $value = $entry[$field] ?? null;
            if ($value !== null && ! preg_match(self::WORKFLOW_RUN_URL_PATTERN, (string) $value)) {
                $errors[] = "enum: Run '$runId'.merges[$index].$field = ".self::describe($value).' is not a turbophp/doccum Actions run URL.';
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $errors
     */
    private static function checkMutation(string $id, array $node, array &$errors): void
    {
        if (isset($node['verdict']) && ! in_array($node['verdict'], self::MUTATION_VERDICTS, true)) {
            $errors[] = "enum: Mutation '$id'.verdict = ".self::describe($node['verdict']).' is not one of negative, positive, void.';
        }
        $headSha = $node['headSha'] ?? null;
        if ($headSha !== null && ! preg_match(self::SHA_PATTERN, (string) $headSha)) {
            $errors[] = "enum: Mutation '$id'.headSha = ".self::describe($headSha).' is not a 40-character hex sha.';
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $errors
     */
    private static function checkRun(string $id, array $node, array &$errors): void
    {
        if (isset($node['outcome']) && ! in_array($node['outcome'], self::RUN_OUTCOMES, true)) {
            $errors[] = "enum: Run '$id'.outcome = ".self::describe($node['outcome']).' is not one of completed, failed, aborted, active.';
        }
        if (isset($node['commit']) && ! preg_match(self::SHA_PATTERN, (string) $node['commit'])) {
            $errors[] = "enum: Run '$id'.commit = ".self::describe($node['commit']).' is not a 40-character hex sha.';
        }
        self::checkRunMerges($id, $node, $errors);
    }

    // -- Ordering ------------------------------------------------------

    /**
     * @param  array<string, mixed>  $ledger
     * @return list<string>
     */
    private static function orderingErrors(array $ledger): array
    {
        $errors = [];

        $orders = [];
        $previousOrder = null;
        foreach (($ledger['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $order = $item['order'] ?? null;
            $id = $item['@id'] ?? '?';

            if (! is_int($order) || $order <= 0) {
                $errors[] = "order: Action '$id'.order = ".self::describe($order).' is not a positive integer.';
            } else {
                if (isset($orders[$order])) {
                    $errors[] = "order: order $order is used by both '{$orders[$order]}' and '$id'.";
                }
                $orders[$order] = $id;

                if ($previousOrder !== null && $order < $previousOrder) {
                    $errors[] = "order: items are not sorted by order ascending -- '$id' (order $order) comes after order $previousOrder.";
                }
                $previousOrder = $order;
            }
        }

        $previousIdentifier = null;
        foreach (($ledger['pullRequests'] ?? []) as $pr) {
            if (! is_array($pr) || ! is_int($pr['identifier'] ?? null)) {
                continue;
            }
            if ($previousIdentifier !== null && $pr['identifier'] < $previousIdentifier) {
                $errors[] = "order: pullRequests are not sorted by identifier ascending -- {$pr['identifier']} comes after $previousIdentifier.";
            }
            $previousIdentifier = $pr['identifier'];
        }

        $previousDecisionId = null;
        foreach (($ledger['decisions'] ?? []) as $decision) {
            if (! is_array($decision) || ! is_string($decision['@id'] ?? null)) {
                continue;
            }
            $id = $decision['@id'];
            if ($previousDecisionId !== null && strcmp($id, $previousDecisionId) < 0) {
                $errors[] = "order: decisions are not sorted by @id ascending -- '$id' comes after '$previousDecisionId'.";
            }
            $previousDecisionId = $id;
        }

        return $errors;
    }

    // -- Canonical serialization ----------------------------------------

    /**
     * Re-serializing the parsed document (2-space indent, \n endings,
     * trailing newline, UTF-8, keys in their existing order) must reproduce
     * the file byte for byte, so hourly rewrites of ledger.jsonld and the
     * run files produce minimal diffs.
     *
     * context.jsonld is deliberately excluded: it is schema, not state the
     * loop rewrites hourly, and its nested single-line term definitions
     * (e.g. `"url": { "@id": "schema:url", "@type": "@id" }`) do not survive
     * a pretty-printer round trip -- there is nothing to keep minimal-diff
     * about a file the loop never touches.
     *
     * @param  array<string, mixed>  $ledger
     * @param  array<string, string>  $runRaws
     * @param  array<string, array<string, mixed>>  $runs
     * @return list<string>
     */
    private static function canonicalSerializationErrors(string $ledgerRaw, array $ledger, array $runRaws, array $runs): array
    {
        $errors = [];

        $canonical = self::canonicalize($ledger);
        if ($canonical !== $ledgerRaw) {
            $errors[] = 'canonical: ledger.jsonld is not canonically formatted (2-space indent, LF, trailing newline, existing key order).';
        }

        foreach ($runs as $filename => $run) {
            $canonicalRun = self::canonicalize($run);
            if ($canonicalRun !== ($runRaws[$filename] ?? null)) {
                $errors[] = "canonical: runs/$filename is not canonically formatted (2-space indent, LF, trailing newline, existing key order).";
            }
        }

        return $errors;
    }

    /**
     * Public rather than private so App\Support\LedgerRuleWitnesses can
     * write a mutated ledger.jsonld/run file back out in the exact byte
     * form this class's own canonicalSerializationErrors() demands --
     * otherwise every witness that edits ledger.jsonld's content would also
     * trip a spurious 'canonical:' error alongside whatever category it
     * actually means to isolate, purely as an artifact of re-encoding.
     *
     * @param array<string, mixed> $data
     */
    public static function canonicalize(array $data): string
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return '';
        }

        // json_encode's PRETTY_PRINT indents in 4-space steps; the ledger's
        // canonical form uses 2, so halve every leading run of spaces.
        $lines = explode("\n", $encoded);
        foreach ($lines as &$line) {
            if (preg_match('/^( +)/', $line, $m)) {
                $levels = intdiv(strlen($m[1]), 4);
                $line = str_repeat('  ', $levels).substr($line, strlen($m[1]));
            }
        }

        return implode("\n", $lines)."\n";
    }

    // -- Graph: dependsOn is acyclic -------------------------------------

    /**
     * @param  array<string, mixed>  $ledger
     * @return list<string>
     */
    private static function acyclicErrors(array $ledger): array
    {
        $edges = [];
        foreach (($ledger['items'] ?? []) as $item) {
            if (! is_array($item) || ! is_string($item['@id'] ?? null)) {
                continue;
            }
            $edges[$item['@id']] = self::stringList($item['dependsOn'] ?? null);
        }

        $state = []; // id => 0 unvisited, 1 in progress, 2 done
        $errors = [];

        $visit = function (string $id, array $path) use (&$visit, &$state, &$errors, $edges): void {
            if (($state[$id] ?? 0) === 2) {
                return;
            }
            if (($state[$id] ?? 0) === 1) {
                $cycle = implode(' -> ', [...$path, $id]);
                $errors[] = "graph: dependsOn has a cycle: $cycle.";

                return;
            }

            $state[$id] = 1;
            foreach ($edges[$id] ?? [] as $dependency) {
                if (isset($edges[$dependency])) {
                    $visit($dependency, [...$path, $id]);
                }
            }
            $state[$id] = 2;
        };

        foreach (array_keys($edges) as $id) {
            $visit($id, []);
        }

        return $errors;
    }

    // -- Action status semantics -----------------------------------------

    /**
     * - Completed requires every dependsOn to be Completed, a non-empty
     *   result, and every result entry that is a PullRequest to be merged.
     * - startTime is present (non-null) iff status is not Potential;
     *   endTime is present iff status is Completed or Failed; when both are
     *   present, startTime <= endTime.
     * - item/tag-v1-0-0 Completed implies every v1.0.0 Action is Completed.
     * - item/ledger-mutation-nodes (issue #150), for runs after
     *   MUTATION_RULES_EFFECTIVE_AFTER_RUN only (see that constant):
     *   a Completed item based on spec:10 is implements-referenced by at
     *   least one negative Mutation with a non-empty check; and a positive
     *   or void Mutation that implements an item blocks that item's
     *   Completion until a later Mutation supersedes it.
     *
     * @param  array<string, mixed>  $ledger
     * @param  array<string, array{type: string, node: array<string, mixed>}>  $nodes
     * @return list<string>
     */
    private static function actionStatusErrors(array $ledger, array $nodes): array
    {
        $errors = [];
        $items = array_filter($ledger['items'] ?? [], 'is_array');

        $statusOf = static function (string $id) use ($nodes): ?string {
            $node = $nodes[$id]['node'] ?? null;

            return is_array($node) ? ($node['actionStatus'] ?? null) : null;
        };

        /** @var array<string, array<string, mixed>> $mutations id => node, Mutation entries only */
        $mutations = [];
        foreach ($nodes as $mutationId => $entry) {
            if ($entry['type'] === 'Mutation') {
                $mutations[$mutationId] = $entry['node'];
            }
        }

        $superseded = [];
        foreach ($mutations as $mutationNode) {
            if (is_string($mutationNode['supersedes'] ?? null)) {
                $superseded[$mutationNode['supersedes']] = true;
            }
        }

        $implementsItem = static function (array $mutationNode, string $itemId): bool {
            return in_array($itemId, self::stringList($mutationNode['implements'] ?? null), true);
        };

        foreach ($items as $item) {
            $id = $item['@id'] ?? null;
            if (! is_string($id)) {
                continue;
            }

            $status = $item['actionStatus'] ?? null;
            $startTime = $item['startTime'] ?? null;
            $endTime = $item['endTime'] ?? null;

            if ($status !== 'PotentialActionStatus' && $startTime === null) {
                $errors[] = "status: Action '$id' has status $status but no startTime.";
            }
            if ($status === 'PotentialActionStatus' && $startTime !== null) {
                $errors[] = "status: Action '$id' is Potential but has a startTime.";
            }
            if (in_array($status, ['CompletedActionStatus', 'FailedActionStatus'], true) && $endTime === null) {
                $errors[] = "status: Action '$id' has status $status but no endTime.";
            }
            if (! in_array($status, ['CompletedActionStatus', 'FailedActionStatus'], true) && $endTime !== null) {
                $errors[] = "status: Action '$id' has status $status but has an endTime.";
            }
            if (is_string($startTime) && is_string($endTime) && strtotime($startTime) > strtotime($endTime)) {
                $errors[] = "status: Action '$id' has startTime after endTime.";
            }

            if ($status === 'CompletedActionStatus') {
                foreach (self::stringList($item['dependsOn'] ?? null) as $dependency) {
                    if ($statusOf($dependency) !== 'CompletedActionStatus') {
                        $errors[] = "status: Action '$id' is Completed but dependsOn '$dependency' is not.";
                    }
                }

                $result = self::stringList($item['result'] ?? null);
                if ($result === []) {
                    $errors[] = "status: Action '$id' is Completed but has an empty result.";
                }
                foreach ($result as $prId) {
                    $prEntry = $nodes[$prId] ?? null;
                    if ($prEntry !== null && $prEntry['type'] === 'PullRequest' && ($prEntry['node']['state'] ?? null) !== 'merged') {
                        $errors[] = "status: Action '$id' is Completed but result '$prId' is not merged.";
                    }
                }

                // Rule 2: an un-superseded positive/void Mutation implementing
                // this item blocks its Completion, at any run -- there is no
                // history to grandfather, since every backfilled positive/void
                // node (mutation/0007) is already superseded.
                foreach ($mutations as $mutationId => $mutationNode) {
                    $verdict = $mutationNode['verdict'] ?? null;
                    if (! in_array($verdict, ['positive', 'void'], true)) {
                        continue;
                    }
                    if (isset($superseded[$mutationId])) {
                        continue;
                    }
                    if ($implementsItem($mutationNode, $id)) {
                        $errors[] = "status: Action '$id' is Completed but Mutation '$mutationId' (verdict $verdict) implements it and is not yet superseded.";
                    }
                }

                // Rule 1: a Completed item based on spec:10, once its own run
                // is past MUTATION_RULES_EFFECTIVE_AFTER_RUN, needs at least
                // one negative Mutation with a non-empty check.
                $isBasedOnSpec10 = false;
                foreach (self::stringList($item['isBasedOn'] ?? null) as $basis) {
                    if (preg_match('/^spec:10(-|$)/', $basis) === 1) {
                        $isBasedOnSpec10 = true;
                        break;
                    }
                }
                if ($isBasedOnSpec10 && self::itemCompletedAfterMutationRulesEffective($item, $nodes)) {
                    $hasNegativeEvidence = false;
                    foreach ($mutations as $mutationNode) {
                        if (($mutationNode['verdict'] ?? null) === 'negative'
                            && is_string($mutationNode['check'] ?? null)
                            && $mutationNode['check'] !== ''
                            && $implementsItem($mutationNode, $id)) {
                            $hasNegativeEvidence = true;
                            break;
                        }
                    }
                    if (! $hasNegativeEvidence) {
                        $errors[] = "status: Action '$id' is Completed and based on spec:10, but no negative Mutation with a non-empty check implements it.";
                    }
                }
            }
        }

        $tag = $nodes['item/tag-v1-0-0']['node'] ?? null;
        if (is_array($tag) && ($tag['actionStatus'] ?? null) === 'CompletedActionStatus') {
            foreach ($items as $item) {
                $id = $item['@id'] ?? null;
                if (is_string($id) && ($item['release'] ?? null) === 'v1.0.0' && ($item['actionStatus'] ?? null) !== 'CompletedActionStatus') {
                    $errors[] = "status: item/tag-v1-0-0 is Completed but '$id' (release v1.0.0) is not.";
                }
            }
        }

        return $errors;
    }

    /**
     * Resolves which run actually produced this item's Completion, through
     * its result PullRequest(s)' mergedIn (falling back to run, though a
     * merged PullRequest always carries mergedIn -- see pullRequestErrors),
     * and compares the highest one found against
     * MUTATION_RULES_EFFECTIVE_AFTER_RUN.
     *
     * An item whose run cannot be resolved at all (no result, or a result
     * pointing at something other than a known PullRequest) is treated as
     * NOT yet subject to the rule: this function only ever makes the rule
     * apply to *more* history, never silently exempts a run once it is
     * resolvable, so failing safe here cannot hide a real gap going forward.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, array{type: string, node: array<string, mixed>}>  $nodes
     */
    private static function itemCompletedAfterMutationRulesEffective(array $item, array $nodes): bool
    {
        $runNumbers = [];
        foreach (self::stringList($item['result'] ?? null) as $prId) {
            $prNode = $nodes[$prId]['node'] ?? null;
            if (! is_array($prNode)) {
                continue;
            }
            $runRef = $prNode['mergedIn'] ?? $prNode['run'] ?? null;
            if (is_string($runRef)) {
                $number = self::runNumber($runRef);
                if ($number !== null) {
                    $runNumbers[] = $number;
                }
            }
        }

        if ($runNumbers === []) {
            return false;
        }

        return max($runNumbers) > self::MUTATION_RULES_EFFECTIVE_AFTER_RUN;
    }

    // -- PullRequest merge invariants ------------------------------------

    /**
     * @param  array<string, mixed>  $ledger
     * @param  array<string, array<string, mixed>>  $runs
     * @param  array<string, array{type: string, node: array<string, mixed>}>  $nodes
     * @return list<string>
     */
    private static function pullRequestErrors(array $ledger, array $runs, array $nodes): array
    {
        $errors = [];

        $touchedByRun = [];
        $mergeShasByRun = [];
        foreach ($runs as $run) {
            $runId = $run['@id'] ?? null;
            if (is_string($runId)) {
                $touchedByRun[$runId] = self::stringList($run['touched'] ?? null);

                $shas = [];
                foreach (($run['merges'] ?? []) as $entry) {
                    if (is_array($entry) && is_string($entry['mergeSha'] ?? null)) {
                        $shas[] = $entry['mergeSha'];
                    }
                }
                $mergeShasByRun[$runId] = $shas;
            }
        }

        foreach (($ledger['pullRequests'] ?? []) as $pr) {
            if (! is_array($pr) || ! is_string($pr['@id'] ?? null)) {
                continue;
            }
            $id = $pr['@id'];
            $state = $pr['state'] ?? null;
            $mergedAt = $pr['mergedAt'] ?? null;
            $mergeSha = $pr['mergeSha'] ?? null;
            $mergedIn = $pr['mergedIn'] ?? null;

            if ($state === 'merged') {
                if ($mergedAt === null || $mergeSha === null || $mergedIn === null) {
                    $errors[] = "pr: '$id' is merged but is missing mergedAt, mergeSha or mergedIn.";
                } elseif (is_string($mergedIn) && is_string($pr['run'] ?? null)) {
                    $mergedInNumber = self::runNumber($mergedIn);
                    $runNumber = self::runNumber($pr['run']);
                    if ($mergedInNumber !== null && $runNumber !== null && $mergedInNumber < $runNumber) {
                        $errors[] = "pr: '$id' has mergedIn '$mergedIn' earlier than its own run '{$pr['run']}'.";
                    }
                }
            } elseif ($mergedAt !== null || $mergeSha !== null || $mergedIn !== null) {
                $errors[] = "pr: '$id' is not merged but has mergedAt, mergeSha or mergedIn set.";
            }

            // item/ledger-main-push-record (issue #172), rule 1: a merged
            // PR's mergeSha must appear in its own mergedIn run's merges --
            // catches an item PR omitted from the list, something nothing
            // checked before Run.merges existed.
            if ($state === 'merged' && is_string($mergeSha) && is_string($mergedIn)) {
                $mergedInShas = $mergeShasByRun[$mergedIn] ?? null;
                if ($mergedInShas !== null && ! in_array($mergeSha, $mergedInShas, true)) {
                    $errors[] = "pr: '$id' has mergeSha '$mergeSha' but it does not appear in its mergedIn run '$mergedIn''s merges.";
                }
            }

            $implements = self::stringList($pr['implements'] ?? null);
            if ($implements === []) {
                $errors[] = "pr: '$id' implements no items.";
            }

            $runId = $pr['run'] ?? null;
            if (is_string($runId)) {
                $touched = $touchedByRun[$runId] ?? null;
                if ($touched !== null) {
                    foreach ($implements as $implementedId) {
                        if (! in_array($implementedId, $touched, true)) {
                            $errors[] = "pr: '$id' implements '$implementedId' but its run '$runId' did not touch it.";
                        }
                    }
                }
            }
        }

        return $errors;
    }

    // -- Decision.supersedes ---------------------------------------------

    /**
     * @param  array<string, mixed>  $ledger
     * @return list<string>
     */
    private static function decisionSupersedesErrors(array $ledger): array
    {
        $errors = [];

        foreach (($ledger['decisions'] ?? []) as $decision) {
            if (! is_array($decision) || ! is_string($decision['@id'] ?? null)) {
                continue;
            }
            $id = $decision['@id'];
            $supersedes = $decision['supersedes'] ?? null;
            if (! is_string($supersedes)) {
                continue;
            }

            $thisNumber = self::decisionNumber($id);
            $otherNumber = self::decisionNumber($supersedes);
            if ($thisNumber !== null && $otherNumber !== null && $otherNumber >= $thisNumber) {
                $errors[] = "decision: '$id' supersedes '$supersedes', which is not a lower-numbered decision.";
            }
        }

        return $errors;
    }

    // -- Mutation.check / Mutation.supersedes -----------------------------

    /**
     * item/ledger-mutation-nodes (issue #150):
     *   - `check` is required (non-empty) exactly when verdict is negative --
     *     a failure that names no assertion is not evidence, and a positive
     *     or void verdict has nothing confirmed to name.
     *   - `implements` is non-empty, same reasoning as PullRequest.implements.
     *   - `supersedes`, when set, names an earlier-numbered Mutation -- same
     *     shape as Decision.supersedes.
     *
     * @param  array<string, mixed>  $ledger
     * @return list<string>
     */
    private static function mutationErrors(array $ledger): array
    {
        $errors = [];

        foreach (($ledger['mutations'] ?? []) as $mutation) {
            if (! is_array($mutation) || ! is_string($mutation['@id'] ?? null)) {
                continue;
            }
            $id = $mutation['@id'];
            $verdict = $mutation['verdict'] ?? null;
            $check = $mutation['check'] ?? null;

            if ($verdict === 'negative' && (! is_string($check) || $check === '')) {
                $errors[] = "mutation: '$id' has verdict negative but no non-empty check -- a failure that names no assertion is not evidence.";
            }
            if ($verdict !== 'negative' && $check !== null && ! is_string($check)) {
                $errors[] = "mutation: '$id'.check = ".self::describe($check).' is neither a string nor null.';
            }

            if (self::stringList($mutation['implements'] ?? null) === []) {
                $errors[] = "mutation: '$id' implements no items.";
            }

            $supersedes = $mutation['supersedes'] ?? null;
            if (is_string($supersedes)) {
                $thisNumber = self::mutationNumber($id);
                $otherNumber = self::mutationNumber($supersedes);
                if ($thisNumber !== null && $otherNumber !== null && $otherNumber >= $thisNumber) {
                    $errors[] = "mutation: '$id' supersedes '$supersedes', which is not a lower-numbered mutation.";
                }
            }
        }

        return $errors;
    }

    // -- Runs --------------------------------------------------------------

    /**
     * - Run files numbered contiguously from 0000, no gaps; @id matches
     *   filename; latestRun is the highest; ledger.dateModified is at or
     *   after latestRun.endTime; each run's startTime is strictly after the
     *   previous run's endTime.
     * - touched is non-empty when outcome = completed.
     * - a Decision or PullRequest whose run is X has dateCreated within X's
     *   [startTime, endTime].
     * - item/ledger-mutation-nodes (issue #150): a run past
     *   MUTATION_RULES_EFFECTIVE_AFTER_RUN may not carry outcome completed
     *   while a PullRequest merged in it lacks mainConclusion.
     * - item/ledger-shape-from-context (issue #188), clause 4 as amended by
     *   decision/0068: every merge entry, in every run, with a non-null
     *   testsConclusion/ledgerConclusion must carry the matching non-null
     *   testsRun/ledgerRun -- a conclusion recorded with no run to point at
     *   is incoherent, unconditionally, not only past a threshold.
     *
     * @param  array<string, mixed>  $ledger
     * @param  array<string, array<string, mixed>>  $runs
     * @return list<string>
     */
    private static function runErrors(array $ledger, array $runs): array
    {
        $errors = [];

        $numbered = [];
        foreach ($runs as $filename => $run) {
            $expectedId = 'run/'.pathinfo($filename, PATHINFO_FILENAME);
            $actualId = $run['@id'] ?? null;
            if ($actualId !== $expectedId) {
                $errors[] = "run: runs/$filename has @id ".self::describe($actualId)." but the filename implies '$expectedId'.";
            }

            $number = is_string($actualId) ? self::runNumber($actualId) : self::runNumber($expectedId);
            if ($number !== null) {
                $numbered[$number] = $run;
            }

            // Run 0000 is exempt: it is the baseline, recording where the
            // project stood before the loop started, so by definition it
            // touched no backlog item. Every run after it that claims to
            // have completed must say what it worked on, or a run that did
            // nothing is indistinguishable from one that did.
            if ($number !== 0 && ($run['outcome'] ?? null) === 'completed' && self::stringList($run['touched'] ?? null) === []) {
                $errors[] = "run: '$expectedId' has outcome completed but an empty touched set.";
            }

            // item/ledger-main-push-record (issue #172), rule 2: Run.commit
            // is DEFINED as the last merges entry's mergeSha -- there is no
            // other stated semantics for the field, and in practice it drifted
            // (run/0017's old commit was main at that run's START, not its
            // end). Vacuous when merges is empty (no last entry to compare --
            // see run/0001, which merged nothing to main in its window).
            $merges = array_values(array_filter($run['merges'] ?? [], 'is_array'));
            if ($merges !== []) {
                $lastEntry = $merges[count($merges) - 1];
                $lastMergeSha = $lastEntry['mergeSha'] ?? null;
                if (is_string($lastMergeSha) && ($run['commit'] ?? null) !== $lastMergeSha) {
                    $errors[] = "run: '$expectedId'.commit = ".self::describe($run['commit'] ?? null)." but its last merges entry's mergeSha is '$lastMergeSha'.";
                }
            }

            // Rule 3: a completed run's last merge must have closed both
            // workflows green, past MERGES_OUTCOME_RULE_EFFECTIVE_AFTER_RUN
            // (see that constant's docblock for why history before it is
            // exempt rather than fixed to fit).
            if ($number !== null
                && $number > self::MERGES_OUTCOME_RULE_EFFECTIVE_AFTER_RUN
                && ($run['outcome'] ?? null) === 'completed'
                && $merges !== []) {
                $last = $merges[count($merges) - 1];
                $testsOk = ($last['testsConclusion'] ?? null) === 'success';
                $ledgerOk = ($last['ledgerConclusion'] ?? null) === 'success';
                if (! $testsOk || ! $ledgerOk) {
                    $errors[] = "run: '$expectedId' has outcome completed but its last merge (".
                        self::describe($last['mergeSha'] ?? null).
                        ') did not close green (testsConclusion = '.self::describe($last['testsConclusion'] ?? null).
                        ', ledgerConclusion = '.self::describe($last['ledgerConclusion'] ?? null).').';
                }
            }

            // Rule 4 (decision/0068, restating item/ledger-shape-from-context's
            // original "mainConclusion with a null mainRunUrl" clause against
            // the fields that inherited its intent): a conclusion recorded
            // with no run that produced it is incoherent. checkMergeEntry()
            // validates mergeSha, pullRequest, the two conclusions and the
            // two run URLs each independently, so without this an entry could
            // claim testsConclusion: "success" with testsRun: null (or the
            // ledger equivalent) and validate clean. Checked on every merge
            // entry, not only the last -- Rule 3 above cares about the run's
            // own closing state, this cares about each entry's own
            // provenance.
            foreach (($run['merges'] ?? []) as $index => $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                if (($entry['testsConclusion'] ?? null) !== null && ($entry['testsRun'] ?? null) === null) {
                    $errors[] = "run: '$expectedId'.merges[$index] has testsConclusion ".self::describe($entry['testsConclusion']).' but testsRun is null -- a conclusion with no run to point at.';
                }
                if (($entry['ledgerConclusion'] ?? null) !== null && ($entry['ledgerRun'] ?? null) === null) {
                    $errors[] = "run: '$expectedId'.merges[$index] has ledgerConclusion ".self::describe($entry['ledgerConclusion']).' but ledgerRun is null -- a conclusion with no run to point at.';
                }
            }
        }

        ksort($numbered);
        $numbers = array_keys($numbered);
        if ($numbers !== [] && ($numbers[0] !== 0 || $numbers !== range(0, count($numbers) - 1))) {
            $errors[] = 'run: run files are not numbered contiguously from 0000 with no gaps (found: '.implode(', ', $numbers).').';
        }

        // 'active' describes the run being worked right now, so only the
        // newest run may carry it. An older run left active is an unfinished
        // record, which is the thing the outcome field exists to prevent --
        // the enum was widened to stop a run in progress having to claim it
        // had completed, not to make completion optional.
        $newest = $numbers === [] ? null : max($numbers);
        foreach ($numbered as $number => $run) {
            if (($run['outcome'] ?? null) === 'active' && $number !== $newest) {
                $errors[] = sprintf('run: run %04d.outcome is active but it is not the latest run.', $number);
            }
        }

        $previousEnd = null;
        foreach ($numbered as $number => $run) {
            $start = $run['startTime'] ?? null;
            $end = $run['endTime'] ?? null;
            if ($previousEnd !== null && is_string($start) && strtotime($start) <= strtotime($previousEnd)) {
                $errors[] = sprintf('run: run %04d.startTime is not after the previous run\'s endTime.', $number);
            }
            if (is_string($end)) {
                $previousEnd = $end;
            }
        }

        $highest = $numbers === [] ? null : max($numbers);
        $latestRun = $ledger['latestRun'] ?? null;
        if ($highest !== null && is_string($latestRun)) {
            if (self::runNumber($latestRun) !== $highest) {
                $errors[] = sprintf("run: ledger.latestRun is '%s' but the highest run file is %04d.", $latestRun, $highest);
            } elseif (isset($numbered[$highest]['endTime']) && is_string($numbered[$highest]['endTime'])) {
                $dateModified = $ledger['dateModified'] ?? null;
                if (is_string($dateModified) && strtotime($dateModified) < strtotime($numbered[$highest]['endTime'])) {
                    $errors[] = 'run: ledger.dateModified is earlier than latestRun.endTime.';
                }
            }
        }

        $windowFor = static function (?string $runId) use ($runs): ?array {
            foreach ($runs as $run) {
                if (($run['@id'] ?? null) === $runId) {
                    return [$run['startTime'] ?? null, $run['endTime'] ?? null];
                }
            }

            return null;
        };

        foreach (($ledger['decisions'] ?? []) as $decision) {
            $errors = array_merge($errors, self::withinRunWindow($decision, $windowFor, 'Decision'));
        }
        foreach (($ledger['pullRequests'] ?? []) as $pr) {
            $errors = array_merge($errors, self::withinRunWindow($pr, $windowFor, 'PullRequest'));
        }

        return $errors;
    }

    /** @return list<string> */
    private static function withinRunWindow(mixed $node, callable $windowFor, string $label): array
    {
        if (! is_array($node) || ! is_string($node['@id'] ?? null) || ! is_string($node['run'] ?? null)) {
            return [];
        }

        $window = $windowFor($node['run']);
        if ($window === null || ! is_string($node['dateCreated'] ?? null)) {
            return [];
        }

        [$start, $end] = $window;
        if (! is_string($start) || ! is_string($end)) {
            return [];
        }

        $created = strtotime($node['dateCreated']);
        if ($created < strtotime($start) || $created > strtotime($end)) {
            return ["run: $label '{$node['@id']}'.dateCreated is outside its run '{$node['run']}''s [startTime, endTime] window."];
        }

        return [];
    }

    // -- main push history (git facts, supplied by the CLI wrapper only) -

    /**
     * item/ledger-main-push-record (issue #172): the clause without which
     * Run.merges is only bookkeeping. Every sha the caller supplies (first-
     * parent commits on main since the previous completed run, per the CLI
     * wrapper's docblock) must appear as some run's merges[].mergeSha --
     * otherwise a merge to main was never recorded anywhere, which is
     * exactly the gap runErrors() alone (walking `pullRequests`) could not
     * see for a ledger-only PR.
     *
     * This is the one check in this file that needs a fact this class
     * cannot derive from the ledger's own files -- see validate()'s
     * docblock for why it is optional and who supplies it.
     *
     * @param  array<string, array<string, mixed>>  $runs
     * @param  list<string>  $mainPushShas
     * @return list<string>
     */
    private static function mainPushHistoryErrors(array $runs, array $mainPushShas): array
    {
        $known = [];
        foreach ($runs as $run) {
            foreach (($run['merges'] ?? []) as $entry) {
                if (is_array($entry) && is_string($entry['mergeSha'] ?? null)) {
                    $known[$entry['mergeSha']] = true;
                }
            }
        }

        // Only commits up to the NEWEST one the ledger records can be
        // required to be recorded. A merge commit's own sha cannot appear in
        // the ledger that merge commit contains -- it does not exist until
        // the merge happens -- so demanding completeness all the way to the
        // tip makes `validate ledger` red on main from the instant any PR
        // merges, and red again after the very ledger pass that recorded the
        // previous merges. That is not a strict rule, it is a deadlock.
        //
        // Contiguity instead: anything BEFORE the newest recorded merge and
        // not itself recorded is a hole somebody left, which is exactly the
        // failure this item exists to catch (both times main went red it was
        // a ledger-only merge nobody wrote down). Anything AFTER it is simply
        // not recorded yet, and becomes interior -- and so caught -- the
        // moment a later merge is recorded.
        $lastKnownIndex = -1;
        foreach ($mainPushShas as $index => $sha) {
            if (isset($known[$sha])) {
                $lastKnownIndex = $index;
            }
        }

        $errors = [];
        foreach ($mainPushShas as $index => $sha) {
            if ($index < $lastKnownIndex && ! isset($known[$sha])) {
                $errors[] = "history: commit '$sha' is on main (per git log since the previous completed run) but does not appear in any run's merges, and a LATER commit does -- so this is a merge nobody wrote down, not one not yet recorded.";
            }
        }

        return $errors;
    }

    // -- isBasedOn spec anchors -------------------------------------------

    /**
     * Every isBasedOn value starts with "spec:"; the anchor after it must
     * correspond to a heading GitHub would render in
     * docs/superpowers/specs/2026-09-15-doccum-design.md.
     *
     * Not reproduced: GitHub's "-1", "-2" suffixing for a *repeated* heading
     * slug. The spec file has no duplicate headings today, so this cannot
     * currently misjudge an anchor either way.
     *
     * @param  array<string, mixed>  $ledger
     * @return list<string>
     */
    private static function specAnchorErrors(array $ledger, string $specPath): array
    {
        if (! is_file($specPath)) {
            return ["fatal: spec file is missing at $specPath; skipping isBasedOn anchor checks."];
        }

        $markdown = file_get_contents($specPath);
        if ($markdown === false) {
            return ["fatal: spec file at $specPath could not be read."];
        }

        $anchors = [];
        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^#{1,6}\s+(.+?)\s*$/', $line, $m)) {
                $anchors[self::githubHeadingAnchor($m[1])] = true;
            }
        }

        $errors = [];
        $check = function (array $node, string $label) use (&$errors, $anchors): void {
            $id = is_string($node['@id'] ?? null) ? $node['@id'] : '?';
            foreach (self::stringList($node['isBasedOn'] ?? null) as $value) {
                if (! str_starts_with($value, 'spec:')) {
                    $errors[] = "spec-anchor: $label '$id' has isBasedOn '$value', which does not start with 'spec:'.";

                    continue;
                }
                $anchor = substr($value, strlen('spec:'));
                if (! isset($anchors[$anchor])) {
                    $errors[] = "spec-anchor: $label '$id' has isBasedOn 'spec:$anchor', but no heading in the design spec produces that anchor.";
                }
            }
        };

        foreach (($ledger['items'] ?? []) as $item) {
            if (is_array($item)) {
                $check($item, 'Action');
            }
        }
        foreach (($ledger['decisions'] ?? []) as $decision) {
            if (is_array($decision)) {
                $check($decision, 'Decision');
            }
        }

        return $errors;
    }

    /**
     * GitHub's heading-to-anchor rule: lowercase, strip anything that is
     * not a letter, digit, space, hyphen or underscore (this drops
     * backticks, periods, parentheses, colons, ...), then turn spaces into
     * hyphens.
     */
    private static function githubHeadingAnchor(string $heading): string
    {
        $slug = mb_strtolower($heading);
        $slug = preg_replace('/[^\p{L}\p{N}\s_-]/u', '', $slug) ?? '';
        $slug = trim($slug);

        return preg_replace('/\s+/', '-', $slug) ?? '';
    }
}

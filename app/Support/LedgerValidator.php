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
 * prefixed with a category tag ("structural:", "id:", "ref:", "spec-anchor:",
 * "enum:", "order:", "canonical:", "graph:", "status:", "pr:", "decision:",
 * "run:", "fatal:") so a caller can group or filter by invariant. An empty
 * list means that invariant holds.
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
    /** JSON-LD keywords and CURIE namespace prefixes in context.jsonld -- infrastructure, not data terms. */
    private const CONTEXT_INFRASTRUCTURE_KEYS = ['@version', '@vocab', '@base', 'schema', 'xsd', 'spec'];

    private const ITEM_ID_PATTERN = '/^item\/[a-z0-9]+(-[a-z0-9]+)*$/';

    private const RUN_ID_PATTERN = '/^run\/\d{4}$/';

    private const DECISION_ID_PATTERN = '/^decision\/\d{4}$/';

    private const PULL_REQUEST_ID_PATTERN = '#^https://github\.com/turbophp/doccum/pull/\d+$#';

    private const SHA_PATTERN = '/^[0-9a-f]{40}$/';

    private const RELEASE_PATTERN = '/^v\d+\.\d+\.\d+$/';

    private const SIZES = ['S', 'M', 'L'];

    private const ACTION_STATUSES = [
        'PotentialActionStatus', 'ActiveActionStatus', 'CompletedActionStatus', 'FailedActionStatus',
    ];

    private const PULL_REQUEST_STATES = ['open', 'merged', 'closed'];

    private const RUN_OUTCOMES = ['completed', 'failed', 'aborted'];

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
        'Ledger' => ['version', 'dateModified', 'latestRun', 'about', 'items', 'pullRequests', 'decisions'],
        'SoftwareApplication' => ['name', 'description', 'version', 'url'],
        'Action' => [
            'order', 'name', 'description', 'isBasedOn', 'size', 'release', 'dependsOn',
            'doneWhen', 'actionStatus', 'startTime', 'endTime', 'result',
        ],
        'Run' => [
            'identifier', 'agent', 'startTime', 'endTime', 'outcome', 'touched',
            'commit', 'tests', 'assertions', 'description',
        ],
        'Decision' => ['dateCreated', 'run', 'name', 'description', 'rationale', 'isBasedOn', 'affects', 'supersedes'],
        'PullRequest' => [
            'identifier', 'name', 'description', 'dateCreated', 'state', 'headSha',
            'mergeSha', 'mergedAt', 'run', 'mergedIn', 'implements',
        ],
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
        'Ledger' => ['version', 'dateModified', 'latestRun', 'about', 'items', 'pullRequests', 'decisions'],
        'SoftwareApplication' => ['name', 'version'],
        'Action' => [
            'order', 'name', 'isBasedOn', 'size', 'release', 'dependsOn',
            'doneWhen', 'actionStatus', 'startTime', 'endTime', 'result',
        ],
        'Run' => ['identifier', 'agent', 'startTime', 'endTime', 'outcome', 'touched', 'commit', 'tests', 'assertions', 'description'],
        'Decision' => ['dateCreated', 'run', 'name', 'rationale', 'isBasedOn', 'affects', 'supersedes'],
        'PullRequest' => ['identifier', 'name', 'dateCreated', 'state', 'run', 'implements'],
    ];

    /**
     * Run everything this class knows how to check.
     *
     * @return list<string>
     */
    public static function validate(string $ledgerDir, string $specPath): array
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

        $errors = array_merge($errors, self::idErrors($nodes));
        $errors = array_merge($errors, self::referenceErrors($ledger, $runs, $nodes));
        $errors = array_merge($errors, self::enumerationErrors($nodes));
        $errors = array_merge($errors, self::orderingErrors($ledger));
        $errors = array_merge($errors, self::canonicalSerializationErrors($ledgerRaw, $ledger, $runRaws, $runs));
        $errors = array_merge($errors, self::acyclicErrors($ledger));
        $errors = array_merge($errors, self::actionStatusErrors($ledger, $nodes));
        $errors = array_merge($errors, self::pullRequestErrors($ledger, $runs, $nodes));
        $errors = array_merge($errors, self::decisionSupersedesErrors($ledger));
        $errors = array_merge($errors, self::runErrors($ledger, $runs));
        $errors = array_merge($errors, self::specAnchorErrors($ledger, $specPath));

        return $errors;
    }

    // -- Loading -------------------------------------------------------

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

    /** @return array<string, mixed>|null */
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
     * @return array<string, string>|null map of filename (e.g. "0000.jsonld") to full path,
     *                                     sorted by filename.
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

    // -- Structure: @type, closed vocabulary, required keys ------------

    /**
     * Walks every node in the ledger and every run file, checking:
     *   - exactly one @type, from the six known types;
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

    // -- @id patterns and uniqueness ------------------------------------

    /**
     * @param  array<string, array{type: string, node: array<string, mixed>}>  $nodes
     * @return list<string>
     */
    private static function idErrors(array $nodes): array
    {
        $errors = [];
        $seen = [];

        foreach ($nodes as $id => $entry) {
            if (isset($seen[$id])) {
                $errors[] = "id: '$id' is used by more than one node.";
            }
            $seen[$id] = true;

            $pattern = match ($entry['type']) {
                'Action' => self::ITEM_ID_PATTERN,
                'Run' => self::RUN_ID_PATTERN,
                'Decision' => self::DECISION_ID_PATTERN,
                'PullRequest' => self::PULL_REQUEST_ID_PATTERN,
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

        $resolveEach = function (array $refs, string $expectedType, string $context) use ($resolve): void {
            foreach ($refs as $ref) {
                if (is_string($ref)) {
                    $resolve($ref, $expectedType, $context);
                }
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

        foreach ($runs as $filename => $run) {
            $id = is_string($run['@id'] ?? null) ? $run['@id'] : $filename;
            $resolveEach($run['touched'] ?? [], 'Action', "Run '$id'.touched");
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
                default => null,
            };
        }

        return $errors;
    }

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
    }

    private static function checkRun(string $id, array $node, array &$errors): void
    {
        if (isset($node['outcome']) && ! in_array($node['outcome'], self::RUN_OUTCOMES, true)) {
            $errors[] = "enum: Run '$id'.outcome = ".self::describe($node['outcome']).' is not one of completed, failed, aborted.';
        }
        if (isset($node['commit']) && ! preg_match(self::SHA_PATTERN, (string) $node['commit'])) {
            $errors[] = "enum: Run '$id'.commit = ".self::describe($node['commit']).' is not a 40-character hex sha.';
        }
        foreach (['tests', 'assertions'] as $field) {
            $value = $node[$field] ?? null;
            if ($value !== null && (! is_int($value) || $value < -1)) {
                $errors[] = "enum: Run '$id'.$field = ".self::describe($value).' is not an integer >= -1.';
            }
        }
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

    private static function canonicalize(array $data): string
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
            $edges[$item['@id']] = array_values(array_filter($item['dependsOn'] ?? [], 'is_string'));
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
                foreach (array_filter($item['dependsOn'] ?? [], 'is_string') as $dependency) {
                    if ($statusOf($dependency) !== 'CompletedActionStatus') {
                        $errors[] = "status: Action '$id' is Completed but dependsOn '$dependency' is not.";
                    }
                }

                $result = array_filter($item['result'] ?? [], 'is_string');
                if ($result === []) {
                    $errors[] = "status: Action '$id' is Completed but has an empty result.";
                }
                foreach ($result as $prId) {
                    $prEntry = $nodes[$prId] ?? null;
                    if ($prEntry !== null && $prEntry['type'] === 'PullRequest' && ($prEntry['node']['state'] ?? null) !== 'merged') {
                        $errors[] = "status: Action '$id' is Completed but result '$prId' is not merged.";
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
        foreach ($runs as $run) {
            $runId = $run['@id'] ?? null;
            if (is_string($runId)) {
                $touchedByRun[$runId] = array_filter($run['touched'] ?? [], 'is_string');
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

            $implements = array_filter($pr['implements'] ?? [], 'is_string');
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

    // -- Runs --------------------------------------------------------------

    /**
     * - Run files numbered contiguously from 0000, no gaps; @id matches
     *   filename; latestRun is the highest; ledger.dateModified is at or
     *   after latestRun.endTime; each run's startTime is strictly after the
     *   previous run's endTime.
     * - touched is non-empty when outcome = completed.
     * - a Decision or PullRequest whose run is X has dateCreated within X's
     *   [startTime, endTime].
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
            if ($number !== 0 && ($run['outcome'] ?? null) === 'completed' && array_filter($run['touched'] ?? [], 'is_string') === []) {
                $errors[] = "run: '$expectedId' has outcome completed but an empty touched set.";
            }
        }

        ksort($numbered);
        $numbers = array_keys($numbered);
        if ($numbers !== [] && ($numbers[0] !== 0 || $numbers !== range(0, count($numbers) - 1))) {
            $errors[] = 'run: run files are not numbered contiguously from 0000 with no gaps (found: '.implode(', ', $numbers).').';
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
            foreach (array_filter($node['isBasedOn'] ?? [], 'is_string') as $value) {
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

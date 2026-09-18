<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * item/ledger-rule-witnesses (issue #189): every category tag
 * LedgerValidator::CODES declares must have a witness -- a structural edit
 * to a disposable copy of the live ledger that a clean baseline does not
 * trip, but the edit does, carrying that tag and no other -- or sit in a
 * baseline that is itself bounded and checked. Framework-free and file-only
 * like LedgerValidator, for the same reason: .github/scripts/validate-ledger.php
 * runs this before `composer install`, with no autoloader.
 *
 * -- Code-level, not site-level, and why ------------------------------------
 *
 * The issue's own text calls site-level coverage ("every `$errors[] =` site
 * is reached by at least one witness") "the whole point", because a rule
 * with no witness at all is exactly the dead-`@id`-check shape #185
 * suffered. That is the stronger answer, and it is not the one implemented
 * here. LedgerValidator has 82 `$errors[] = ` call sites behind 16 category
 * tags -- as many as 15 sites sharing one tag (`enum:`). Site-level coverage
 * would need:
 *
 *   - a distinct STABLE CODE per site, not just a category tag, so a census
 *     can name which of the 15 `enum:` sites fired rather than only that
 *     `enum:` did -- LedgerValidator carries no such per-site identifier
 *     today, so this alone is a real change to every one of the 82 lines;
 *   - 82 independent structural selectors, each isolating ONE call site with
 *     no other site (in ANY category) also firing -- several sites differ
 *     only in which field of the same node they check (checkPullRequest's
 *     headSha vs mergeSha loop is ONE call site for two fields; separating
 *     them needs LedgerValidator's own logic restructured, not just a new
 *     witness); several others are unreachable by a JSON edit at all (the
 *     five `fatal:` sites for a missing context.jsonld, a missing runs/
 *     directory, or zero run files found are conditions about which FILES
 *     exist, not what they contain -- the "copy of the live ledger" a
 *     witness edits would have to selectively not exist, which is a
 *     different mutation shape than every other site's);
 *   - proof each of the 82 is independently reachable without deleting a
 *     DIFFERENT site's own effect first (collectAndCheckStructure()'s single
 *     closure serves six node kinds through one pair of call sites -- Action
 *     vs PullRequest vs Decision are separated by which $context string is
 *     interpolated in, not by a distinct line each).
 *
 * That is a rewrite of LedgerValidator's own internals, not an M-sized
 * addition on top of it. So this class witnesses at CODE level: one
 * structural edit per entry in LedgerValidator::CODES, chosen where possible
 * to land on one SPECIFIC call site per tag (see each witness's own comment
 * for which), so a witness at least proves ONE real line per category is
 * load-bearing -- and .github/mutations.json proves exactly that line's
 * removal reddens this class's own run(), the same way #186 already does
 * for one hand-written rule. It is the weaker of the two answers the issue
 * names, adopted because the stronger one was not achievable at this item's
 * size; see the four bullets above for what would have been needed instead.
 *
 * -- The baseline ratchet ----------------------------------------------------
 *
 * self::UNWITNESSED_BASELINE lists codes with no witness below; run()
 * requires LedgerValidator::CODES to equal witnessed-codes UNION that list,
 * exactly (missing OR extra either fails). It is empty, because all 16
 * declared codes turned out witnessable.
 *
 * There was a UNWITNESSED_BASELINE_MAX ceiling here, compared against the
 * list's size on every run. phpstan deleted the illusion: "Comparison
 * operation > between 0 and 0 is always false". With an empty baseline and
 * a ceiling of zero, that guard could not fire -- a check that cannot fail,
 * inside the very class written to make checks that cannot fail visible.
 * Both constants were compile-time known, so the comparison was decorative.
 *
 * What actually holds the line is in the test, not here: a Pest assertion
 * that UNWITNESSED_BASELINE is still empty. That is a tripwire on the
 * SOURCE rather than on runtime state, and it is the honest shape for this
 * invariant at its floor -- the baseline starts empty and has nowhere to
 * ratchet down to, so the only thing worth detecting is someone adding an
 * entry. Adding one then means editing the test in the same diff, which a
 * reviewer reads plainly, and which should arrive alongside the witness
 * that makes the entry unnecessary.
 */
final class LedgerRuleWitnesses
{
    /**
     * Codes with no witness below. Empty: every LedgerValidator::CODES
     * entry has one. Kept as a real, checked list rather than removed
     * outright, so a future code that genuinely cannot be witnessed this
     * way (see this class's own docblock on why the five `fatal:` sites
     * about missing FILES are a different mutation shape) has an explicit,
     * bounded home instead of silently passing run()'s coverage check.
     *
     * @var list<string>
     */
    public const UNWITNESSED_BASELINE = [];

    /**
     * Copies $liveLedgerDir, asserts the copy validates clean (decision/0067:
     * this is a regression check on the DATA, not a witness of anything --
     * it exists only so a witness's own failure cannot be blamed on a ledger
     * that was already broken), then runs every witness below against a
     * fresh mutated copy of that clean baseline and reports which codes were
     * proven, which failed, and whether the declared/witnessed/baseline
     * accounting in this class's own docblock still holds.
     *
     * @return array{failures: list<string>, witnessed: list<string>}
     */
    public static function run(string $liveLedgerDir, string $specPath): array
    {
        $failures = [];
        $witnessed = [];

        $baselineDir = self::copyToTemp($liveLedgerDir);

        try {
            $baselineErrors = LedgerValidator::validate($baselineDir, $specPath);
            if ($baselineErrors !== []) {
                $failures[] = sprintf(
                    'baseline: the unmodified copy of %s does not validate clean (%d error(s), first: %s) -- no witness below can prove anything until the live ledger itself does.',
                    $liveLedgerDir,
                    count($baselineErrors),
                    $baselineErrors[0],
                );

                return ['failures' => $failures, 'witnessed' => $witnessed];
            }

            foreach (self::witnesses() as $witness) {
                $problem = self::runOne($witness, $baselineDir, $specPath);
                if ($problem === null) {
                    $witnessed[] = $witness['code'];
                } else {
                    $failures[] = $problem;
                }
            }
        } finally {
            self::removeTree($baselineDir);
        }

        $witnessed = array_values(array_unique($witnessed));
        sort($witnessed);

        $overlap = array_values(array_intersect($witnessed, self::UNWITNESSED_BASELINE));
        if ($overlap !== []) {
            $failures[] = 'ratchet: '.implode(', ', $overlap).
                ' appear(s) in both the witnessed set and UNWITNESSED_BASELINE -- a code that IS witnessed must not also sit in the baseline, or the baseline is recording coverage that already exists rather than its absence.';
        }

        $declared = LedgerValidator::CODES;
        $covered = array_values(array_unique(array_merge($witnessed, self::UNWITNESSED_BASELINE)));

        $missing = array_values(array_diff($declared, $covered));
        if ($missing !== []) {
            $failures[] = 'coverage: '.implode(', ', $missing).
                ' are declared by LedgerValidator::CODES but neither witnessed nor in UNWITNESSED_BASELINE -- doneWhen clause 3 requires witnessed UNION baseline to equal declared.';
        }

        $unknown = array_values(array_diff($covered, $declared));
        if ($unknown !== []) {
            $failures[] = 'coverage: '.implode(', ', $unknown).
                ' were witnessed or baselined but LedgerValidator::CODES no longer declares them -- a witness or the baseline names a code the validator does not emit any more.';
        }

        return ['failures' => $failures, 'witnessed' => $witnessed];
    }

    /**
     * Runs one witness against a fresh copy of the (already proven clean)
     * baseline, and checks both halves of doneWhen clause 2: the expected
     * code fires, and nothing else does.
     *
     * @param  array{id: string, code: string, select: string, mutate: callable(string): (list<string>|null)}  $witness
     */
    private static function runOne(array $witness, string $baselineDir, string $specPath): ?string
    {
        $dir = self::copyToTemp($baselineDir);

        try {
            $mainPushShas = ($witness['mutate'])($dir);
            $errors = LedgerValidator::validate($dir, $specPath, $mainPushShas);
        } finally {
            self::removeTree($dir);
        }

        $categories = array_values(array_unique(array_map(
            static fn (string $error): string => explode(':', $error, 2)[0],
            $errors,
        )));

        if (! in_array($witness['code'], $categories, true)) {
            return sprintf(
                "%s: expected at least one '%s:' error after %s, got %s.",
                $witness['id'],
                $witness['code'],
                $witness['select'],
                $categories === [] ? 'none at all' : 'only '.implode(', ', array_map(static fn (string $c): string => "'$c:'", $categories)),
            );
        }

        $other = array_values(array_diff($categories, [$witness['code']]));
        if ($other !== []) {
            return sprintf(
                "%s: %s also tripped %s -- a witness must isolate '%s:', not trip an unrelated category too.",
                $witness['id'],
                $witness['select'],
                implode(', ', array_map(static fn (string $c): string => "'$c:'", $other)),
                $witness['code'],
            );
        }

        return null;
    }

    /**
     * One entry per LedgerValidator::CODES tag. Every `select` string
     * describes a STRUCTURAL selector -- "the first Action with actionStatus
     * PotentialActionStatus", "the highest-numbered Decision" -- resolved
     * fresh against each copy's own current content inside `mutate`, never
     * against a hard-coded id: the live ledger changes shape under a
     * fast-moving hourly loop, and a witness pinned to today's ids is
     * tomorrow's dead rule wearing a different costume.
     *
     * @return list<array{id: string, code: string, select: string, mutate: callable(string): (list<string>|null)}>
     */
    private static function witnesses(): array
    {
        return [
            [
                'id' => 'structural-undeclared-key',
                'code' => 'structural',
                'select' => 'adding an undefined key to the first Action in ledger.items',
                /** @return list<string>|null */
                'mutate' => static function (string $dir): ?array {
                    $ledger = self::readLedger($dir);
                    if (! isset($ledger['items'][0]) || ! is_array($ledger['items'][0])) {
                        throw new RuntimeException('witness setup: ledger.items[0] is missing.');
                    }
                    $ledger['items'][0]['ledgerRuleWitnessProbeKey'] = true;
                    self::writeLedger($dir, $ledger);

                    return null;
                },
            ],
            [
                'id' => 'id-pattern-violation',
                'code' => 'id',
                'select' => 'appending a character to the highest-numbered Decision\'s own @id',
                /** @return list<string>|null */
                'mutate' => static function (string $dir): ?array {
                    $ledger = self::readLedger($dir);
                    $index = self::indexOfHighestNumbered($ledger['decisions'] ?? [], '/^decision\/(\d{4})$/');
                    $ledger['decisions'][$index]['@id'] = (string) $ledger['decisions'][$index]['@id'].'x';
                    self::writeLedger($dir, $ledger);

                    return null;
                },
            ],
            [
                'id' => 'ref-dependson-target-missing',
                'code' => 'ref',
                'select' => "appending a non-existent id to the first Action with actionStatus PotentialActionStatus's own dependsOn",
                /** @return list<string>|null */
                'mutate' => static function (string $dir): ?array {
                    $ledger = self::readLedger($dir);
                    $index = self::indexWhere($ledger['items'] ?? [], static fn (array $item): bool => ($item['actionStatus'] ?? null) === 'PotentialActionStatus');
                    $existing = is_array($ledger['items'][$index]['dependsOn'] ?? null) ? $ledger['items'][$index]['dependsOn'] : [];
                    $ledger['items'][$index]['dependsOn'] = array_merge($existing, ['item/ledger-rule-witnesses-probe-does-not-exist']);
                    self::writeLedger($dir, $ledger);

                    return null;
                },
            ],
            [
                'id' => 'enum-pr-headsha-not-hex',
                'code' => 'enum',
                'select' => 'overwriting headSha on the first merged PullRequest that has one',
                /** @return list<string>|null */
                'mutate' => static function (string $dir): ?array {
                    $ledger = self::readLedger($dir);
                    $index = self::indexWhere($ledger['pullRequests'] ?? [], static fn (array $pr): bool => ($pr['state'] ?? null) === 'merged' && is_string($pr['headSha'] ?? null));
                    $ledger['pullRequests'][$index]['headSha'] = 'not-a-hex-sha';
                    self::writeLedger($dir, $ledger);

                    return null;
                },
            ],
            [
                'id' => 'canonical-trailing-byte',
                'code' => 'canonical',
                'select' => 'appending an extra newline to ledger.jsonld\'s own raw bytes, with no semantic change',
                /** @return list<string>|null */
                'mutate' => static function (string $dir): ?array {
                    $path = $dir.'/ledger.jsonld';
                    $raw = (string) file_get_contents($path);
                    file_put_contents($path, $raw."\n");

                    return null;
                },
            ],
            [
                'id' => 'graph-self-cycle',
                'code' => 'graph',
                'select' => 'adding the first Action with actionStatus PotentialActionStatus as its own dependsOn',
                /** @return list<string>|null */
                'mutate' => static function (string $dir): ?array {
                    $ledger = self::readLedger($dir);
                    $index = self::indexWhere($ledger['items'] ?? [], static fn (array $item): bool => ($item['actionStatus'] ?? null) === 'PotentialActionStatus');
                    $id = $ledger['items'][$index]['@id'];
                    $existing = is_array($ledger['items'][$index]['dependsOn'] ?? null) ? $ledger['items'][$index]['dependsOn'] : [];
                    $ledger['items'][$index]['dependsOn'] = array_merge($existing, [$id]);
                    self::writeLedger($dir, $ledger);

                    return null;
                },
            ],
            [
                'id' => 'status-completed-missing-starttime',
                'code' => 'status',
                'select' => 'nulling startTime on the first Action with actionStatus CompletedActionStatus',
                /** @return list<string>|null */
                'mutate' => static function (string $dir): ?array {
                    $ledger = self::readLedger($dir);
                    $index = self::indexWhere($ledger['items'] ?? [], static fn (array $item): bool => ($item['actionStatus'] ?? null) === 'CompletedActionStatus');
                    $ledger['items'][$index]['startTime'] = null;
                    self::writeLedger($dir, $ledger);

                    return null;
                },
            ],
            [
                'id' => 'pr-implements-empty',
                'code' => 'pr',
                'select' => 'emptying implements on the first merged PullRequest',
                /** @return list<string>|null */
                'mutate' => static function (string $dir): ?array {
                    $ledger = self::readLedger($dir);
                    $index = self::indexWhere($ledger['pullRequests'] ?? [], static fn (array $pr): bool => ($pr['state'] ?? null) === 'merged');
                    $ledger['pullRequests'][$index]['implements'] = [];
                    self::writeLedger($dir, $ledger);

                    return null;
                },
            ],
            [
                'id' => 'decision-supersedes-self',
                'code' => 'decision',
                'select' => 'pointing the lowest-numbered Decision\'s own supersedes at itself',
                /** @return list<string>|null */
                'mutate' => static function (string $dir): ?array {
                    $ledger = self::readLedger($dir);
                    $index = self::indexOfLowestNumbered($ledger['decisions'] ?? [], '/^decision\/(\d{4})$/');
                    $ledger['decisions'][$index]['supersedes'] = $ledger['decisions'][$index]['@id'];
                    self::writeLedger($dir, $ledger);

                    return null;
                },
            ],
            [
                'id' => 'mutation-negative-empty-check',
                'code' => 'mutation',
                'select' => 'blanking check on the first negative Mutation with a non-empty check that implements no spec:10 item',
                /** @return list<string>|null */
                'mutate' => static function (string $dir): ?array {
                    $ledger = self::readLedger($dir);
                    $itemsBySpec10 = [];
                    foreach ($ledger['items'] ?? [] as $item) {
                        if (! is_array($item) || ! is_string($item['@id'] ?? null)) {
                            continue;
                        }
                        $itemsBySpec10[$item['@id']] = self::isBasedOnSpec10($item['isBasedOn'] ?? []);
                    }

                    $index = self::indexWhere($ledger['mutations'] ?? [], static function (array $mutation) use ($itemsBySpec10): bool {
                        if (($mutation['verdict'] ?? null) !== 'negative') {
                            return false;
                        }
                        if (! is_string($mutation['check'] ?? null) || $mutation['check'] === '') {
                            return false;
                        }
                        foreach ((array) ($mutation['implements'] ?? []) as $implementedId) {
                            if (($itemsBySpec10[$implementedId] ?? false) === true) {
                                return false;
                            }
                        }

                        return true;
                    });
                    $ledger['mutations'][$index]['check'] = '';
                    self::writeLedger($dir, $ledger);

                    return null;
                },
            ],
            [
                'id' => 'run-commit-mismatch',
                'code' => 'run',
                'select' => "flipping the last hex digit of the newest run file's own commit field",
                /** @return list<string>|null */
                'mutate' => static function (string $dir): ?array {
                    $path = self::newestRunPath($dir);
                    $run = self::readJsonFile($path);
                    $commit = (string) ($run['commit'] ?? '');
                    if ($commit === '') {
                        throw new RuntimeException('witness setup: newest run has no commit.');
                    }
                    $lastChar = $commit[strlen($commit) - 1];
                    $run['commit'] = substr($commit, 0, -1).($lastChar === '0' ? '1' : '0');
                    file_put_contents($path, LedgerValidator::canonicalize($run));

                    return null;
                },
            ],
            [
                'id' => 'shape-dependson-non-string',
                'code' => 'shape',
                'select' => "appending a non-string element to the first Action with actionStatus PotentialActionStatus's own dependsOn",
                /** @return list<string>|null */
                'mutate' => static function (string $dir): ?array {
                    $ledger = self::readLedger($dir);
                    $index = self::indexWhere($ledger['items'] ?? [], static fn (array $item): bool => ($item['actionStatus'] ?? null) === 'PotentialActionStatus');
                    $existing = is_array($ledger['items'][$index]['dependsOn'] ?? null) ? $ledger['items'][$index]['dependsOn'] : [];
                    $ledger['items'][$index]['dependsOn'] = array_merge($existing, [42]);
                    self::writeLedger($dir, $ledger);

                    return null;
                },
            ],
            [
                'id' => 'spec-anchor-bogus',
                'code' => 'spec-anchor',
                'select' => 'overwriting isBasedOn on the last Action in ledger.items',
                /** @return list<string>|null */
                'mutate' => static function (string $dir): ?array {
                    $ledger = self::readLedger($dir);
                    $items = $ledger['items'] ?? [];
                    $index = count($items) - 1;
                    if ($index < 0) {
                        throw new RuntimeException('witness setup: ledger.items is empty.');
                    }
                    $ledger['items'][$index]['isBasedOn'] = ['spec:totally-bogus-heading-that-does-not-exist'];
                    self::writeLedger($dir, $ledger);

                    return null;
                },
            ],
            [
                'id' => 'fatal-ledger-not-json',
                'code' => 'fatal',
                'select' => 'overwriting ledger.jsonld with content that is not valid JSON',
                /** @return list<string>|null */
                'mutate' => static function (string $dir): ?array {
                    file_put_contents($dir.'/ledger.jsonld', '{not valid json');

                    return null;
                },
            ],
            [
                'id' => 'order-duplicate',
                'code' => 'order',
                'select' => "setting the second entry in ledger.items' own order equal to the first entry's",
                /** @return list<string>|null */
                'mutate' => static function (string $dir): ?array {
                    $ledger = self::readLedger($dir);
                    if (! isset($ledger['items'][0]['order'], $ledger['items'][1])) {
                        throw new RuntimeException('witness setup: ledger.items needs at least two entries.');
                    }
                    $ledger['items'][1]['order'] = $ledger['items'][0]['order'];
                    self::writeLedger($dir, $ledger);

                    return null;
                },
            ],
            [
                'id' => 'history-unrecorded-interior-merge',
                'code' => 'history',
                'select' => "supplying a fabricated commit before the newest run's own last recorded mergeSha, as \$mainPushShas -- this rule's fact comes from the CALLER's argument (LedgerValidator stays blind to git itself, see its own validate() docblock), never from ledger.jsonld's content, so this is the one witness that varies validate()'s third parameter instead of editing a file",
                /** @return list<string> */
                'mutate' => static function (string $dir): array {
                    $path = self::newestRunPath($dir);
                    $run = self::readJsonFile($path);
                    $merges = array_values(array_filter($run['merges'] ?? [], 'is_array'));
                    if ($merges === []) {
                        throw new RuntimeException('witness setup: newest run has no merges to anchor a history: witness on.');
                    }
                    $known = $merges[count($merges) - 1]['mergeSha'] ?? null;
                    if (! is_string($known)) {
                        throw new RuntimeException('witness setup: newest run\'s last merge has no mergeSha.');
                    }
                    $fake = hash('sha1', 'ledger-rule-witnesses-history-probe');

                    return [$fake, $known];
                },
            ],
        ];
    }

    // -- Selection helpers -------------------------------------------------

    /**
     * @param  list<mixed>  $nodes
     * @param  callable(array<string, mixed>): bool  $predicate
     */
    private static function indexWhere(array $nodes, callable $predicate): int
    {
        foreach ($nodes as $index => $node) {
            if (is_array($node) && $predicate($node)) {
                return (int) $index;
            }
        }

        throw new RuntimeException('witness setup: no node matched the selector.');
    }

    /** @param list<mixed> $nodes */
    private static function indexOfHighestNumbered(array $nodes, string $idPattern): int
    {
        $bestIndex = null;
        $bestNumber = -1;
        foreach ($nodes as $index => $node) {
            if (! is_array($node) || ! is_string($node['@id'] ?? null)) {
                continue;
            }
            if (preg_match($idPattern, $node['@id'], $m) === 1 && (int) $m[1] > $bestNumber) {
                $bestNumber = (int) $m[1];
                $bestIndex = (int) $index;
            }
        }
        if ($bestIndex === null) {
            throw new RuntimeException('witness setup: no node matched the id pattern.');
        }

        return $bestIndex;
    }

    /** @param list<mixed> $nodes */
    private static function indexOfLowestNumbered(array $nodes, string $idPattern): int
    {
        $bestIndex = null;
        $bestNumber = PHP_INT_MAX;
        foreach ($nodes as $index => $node) {
            if (! is_array($node) || ! is_string($node['@id'] ?? null)) {
                continue;
            }
            if (preg_match($idPattern, $node['@id'], $m) === 1 && (int) $m[1] < $bestNumber) {
                $bestNumber = (int) $m[1];
                $bestIndex = (int) $index;
            }
        }
        if ($bestIndex === null) {
            throw new RuntimeException('witness setup: no node matched the id pattern.');
        }

        return $bestIndex;
    }

    private static function isBasedOnSpec10(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $basis) {
            if (is_string($basis) && preg_match('/^spec:10(-|$)/', $basis) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function newestRunPath(string $dir): string
    {
        $paths = glob($dir.'/runs/*.jsonld');
        if ($paths === false || $paths === []) {
            throw new RuntimeException("witness setup: no run files under $dir/runs.");
        }
        sort($paths, SORT_STRING);

        return (string) end($paths);
    }

    // -- File I/O ------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function readJsonFile(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException("witness setup: $path did not decode to a JSON object.");
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function readLedger(string $dir): array
    {
        return self::readJsonFile($dir.'/ledger.jsonld');
    }

    /** @param array<string, mixed> $ledger */
    private static function writeLedger(string $dir, array $ledger): void
    {
        file_put_contents($dir.'/ledger.jsonld', LedgerValidator::canonicalize($ledger));
    }

    /**
     * Copies context.jsonld, ledger.jsonld and every runs/*.jsonld file into
     * a fresh temp directory, the same shape LedgerIntegrityTest.php's own
     * fixture helpers use, so a witness's edit never touches $sourceDir.
     */
    private static function copyToTemp(string $sourceDir): string
    {
        $dir = sys_get_temp_dir().'/ledger-witness-'.bin2hex(random_bytes(8));
        if (! mkdir($dir.'/runs', 0777, true) && ! is_dir($dir.'/runs')) {
            throw new RuntimeException("witness setup: could not create $dir/runs.");
        }

        foreach (['context.jsonld', 'ledger.jsonld'] as $file) {
            if (is_file($sourceDir.'/'.$file)) {
                copy($sourceDir.'/'.$file, $dir.'/'.$file);
            }
        }

        foreach ((array) glob($sourceDir.'/runs/*.jsonld') as $path) {
            copy((string) $path, $dir.'/runs/'.basename((string) $path));
        }

        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        foreach ((array) glob($dir.'/runs/*.jsonld') as $path) {
            unlink((string) $path);
        }
        if (is_dir($dir.'/runs')) {
            rmdir($dir.'/runs');
        }
        foreach ((array) glob($dir.'/*.jsonld') as $path) {
            unlink((string) $path);
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
    }
}

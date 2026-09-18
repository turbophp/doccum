<?php

declare(strict_types=1);

use App\Support\LedgerValidator;

/*
|--------------------------------------------------------------------------
| Run.merges rules (item/ledger-main-push-record, issue #172)
|--------------------------------------------------------------------------
|
| runErrors() used to walk `pullRequests` to decide whether a run's merged
| PRs had gone green -- so it only ever saw a merge that carried a
| PullRequest node, and a ledger-only PR (no node at all, by consistent
| practice rather than any rule) was invisible to it. Both times main has
| actually gone red, it was exactly that uncovered class.
|
| Run.merges replaces that enumeration with one the run itself owns: a list
| of every push to main in its window, PullRequest or not. This file proves
| the three rules that follow it, in the same disposable-fixture style as
| MutationNodeRulesTest.php and DuplicateIdRuleTest.php:
|   1. every merged PullRequest's mergeSha appears in its mergedIn run's
|      merges;
|   2. Run.commit is DEFINED as the last merges entry's mergeSha;
|   3. outcome: completed requires the last entry's testsConclusion and
|      ledgerConclusion to both be "success" -- gated behind
|      MERGES_OUTCOME_RULE_EFFECTIVE_AFTER_RUN (8), because real history
|      violates it twice (run/0000's last merge, and run/0008's -- see that
|      constant's own docblock) and rewriting `outcome` to hide that is
|      exactly the fudging CLAUDE.md's mutation-check discipline exists to
|      catch. 8 is the LAST run that violates, not the newest run that
|      exists: the gate must grandfather what history forces and nothing
|      more, or it exempts twelve runs that would have passed. The constant
|      is private, so its value is asserted against directly here, the same
|      as MutationNodeRulesTest.php does for
|      MUTATION_RULES_EFFECTIVE_AFTER_RUN.
|   4. the external-list check: every commit on main BEFORE the newest one
|      the ledger records must itself be recorded. This is the clause the
|      item calls the difference between a rule and bookkeeping, and it is
|      the only one that can catch a merge nobody wrote down -- so it needs
|      an assertion that FAILS, not merely the "no history: errors here"
|      line in LedgerIntegrityTest.php, which survives deleting the check.
|
| docs/ is dockerignored (see CLAUDE.md), so these tests skip cleanly when
| docs/ledger is absent, same as the other Ledger test files.
*/

const MERGES_OUTCOME_RULE_EFFECTIVE_AFTER_RUN_UNDER_TEST = 8;

beforeEach(function () {
    if (! is_dir(base_path('docs/ledger'))) {
        $this->markTestSkipped('docs/ is absent -- dockerignored, so it does not exist inside the built image.');
    }
});

/** Byte-for-byte the same rule LedgerValidator::canonicalize() applies, so a hand-built fixture starts with zero 'canonical:' noise. */
function canonicalizeMergesFixtureJson(array $data): string
{
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $lines = explode("\n", $encoded);
    foreach ($lines as &$line) {
        if (preg_match('/^( +)/', $line, $m)) {
            $levels = intdiv(strlen($m[1]), 4);
            $line = str_repeat('  ', $levels).substr($line, strlen($m[1]));
        }
    }

    return implode("\n", $lines)."\n";
}

function writeMergesFixtureFile(string $path, array $data): void
{
    file_put_contents($path, canonicalizeMergesFixtureJson($data));
}

function removeMergesFixture(string $dir): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function mergesFixtureSpecPath(): string
{
    return base_path('docs/superpowers/specs/2026-09-15-doccum-design.md');
}

/**
 * A minimal two-run fixture (run/0000 baseline, run/0001 completes one
 * item via one merged PR) for the two rules that apply at any run number:
 * a merged PR's mergeSha must be in its mergedIn run's merges, and
 * Run.commit must equal the last merges entry's mergeSha.
 *
 * @return string the fixture directory's path
 */
function makeSmallMergesFixture(array $run1Merges, string $run1Commit, string $itemId = 'item/merges-widget'): string
{
    $dir = sys_get_temp_dir().'/ledger-merges-fixture-'.bin2hex(random_bytes(8));
    mkdir($dir.'/runs', 0777, true);

    copy(base_path('docs/ledger/context.jsonld'), $dir.'/context.jsonld');

    $prUrl = 'https://github.com/turbophp/doccum/pull/9101';

    $run0 = [
        '@context' => '../context.jsonld',
        '@id' => 'run/0000',
        '@type' => 'Run',
        'identifier' => 0,
        'agent' => 'https://claude.ai/code/session_fixture',
        'startTime' => '2030-01-01T00:00:00Z',
        'endTime' => '2030-01-01T01:00:00Z',
        'outcome' => 'completed',
        'touched' => [],
        'commit' => str_repeat('a', 40),
        'description' => 'Fixture baseline run.',
        'merges' => [],
    ];
    $run1 = [
        '@context' => '../context.jsonld',
        '@id' => 'run/0001',
        '@type' => 'Run',
        'identifier' => 1,
        'agent' => 'https://claude.ai/code/session_fixture',
        'startTime' => '2030-01-01T02:00:00Z',
        'endTime' => '2030-01-01T03:00:00Z',
        'outcome' => 'completed',
        'touched' => [$itemId],
        'commit' => $run1Commit,
        'description' => 'Fixture run that completes the item under test.',
        'merges' => $run1Merges,
    ];
    writeMergesFixtureFile($dir.'/runs/0000.jsonld', $run0);
    writeMergesFixtureFile($dir.'/runs/0001.jsonld', $run1);

    $ledger = [
        '@context' => 'context.jsonld',
        '@id' => 'ledger',
        '@type' => 'Ledger',
        'version' => '1',
        'dateModified' => '2030-01-01T03:00:00Z',
        'latestRun' => 'run/0001',
        'about' => [
            '@id' => 'https://example.test/fixture',
            '@type' => 'SoftwareApplication',
            'name' => 'fixture',
            'version' => '0.0.0',
        ],
        'items' => [
            [
                '@id' => $itemId,
                '@type' => 'Action',
                'order' => 1,
                'name' => 'Fixture widget',
                'isBasedOn' => ['spec:13-infrastructure'],
                'size' => 'S',
                'release' => 'v1.0.0',
                'dependsOn' => [],
                'doneWhen' => 'Fixture only.',
                'actionStatus' => 'CompletedActionStatus',
                'startTime' => '2030-01-01T02:00:00Z',
                'endTime' => '2030-01-01T03:00:00Z',
                'result' => [$prUrl],
            ],
        ],
        'pullRequests' => [
            [
                '@id' => $prUrl,
                '@type' => 'PullRequest',
                'identifier' => 9101,
                'name' => 'Fixture PR',
                'dateCreated' => '2030-01-01T02:00:00Z',
                'state' => 'merged',
                'headSha' => str_repeat('c', 40),
                'mergeSha' => str_repeat('d', 40),
                'mergedAt' => '2030-01-01T02:30:00Z',
                'run' => 'run/0001',
                'mergedIn' => 'run/0001',
                'implements' => [$itemId],
            ],
        ],
        'decisions' => [],
        'mutations' => [],
    ];
    writeMergesFixtureFile($dir.'/ledger.jsonld', $ledger);

    return $dir;
}

/**
 * The merges entry every "sound" small fixture above uses -- one merged PR,
 * both workflows green, each conclusion carrying the run URL that produced
 * it. item/ledger-shape-from-context (issue #188), clause 4: a conclusion
 * recorded with no run to point at is itself an error now, so "sound" must
 * mean carrying both, the same as every real entry on main does.
 */
function mergesFixtureSoundEntry(): array
{
    return [
        'mergeSha' => str_repeat('d', 40),
        'pullRequest' => 'https://github.com/turbophp/doccum/pull/9101',
        'mergedAt' => '2030-01-01T02:30:00Z',
        'testsRun' => 'https://github.com/turbophp/doccum/actions/runs/1',
        'testsConclusion' => 'success',
        'ledgerRun' => 'https://github.com/turbophp/doccum/actions/runs/2',
        'ledgerConclusion' => 'success',
    ];
}

// -- Rule 1: a merged PR's mergeSha must be in its mergedIn run's merges --

it('requires a merged PullRequest\'s mergeSha to appear in its mergedIn run\'s merges', function () {
    $dir = makeSmallMergesFixture([mergesFixtureSoundEntry()], str_repeat('d', 40));
    try {
        expect(LedgerValidator::validate($dir, mergesFixtureSpecPath()))->toBe([]);
    } finally {
        removeMergesFixture($dir);
    }

    // Mutation-check: drop the merges entry entirely -- the PR's own
    // mergeSha is still 'dddd...', but run/0001.merges no longer lists it,
    // exactly the omission Run.merges exists to catch.
    $dir = makeSmallMergesFixture([], str_repeat('d', 40));
    try {
        $errors = LedgerValidator::validate($dir, mergesFixtureSpecPath());
        expect($errors)->toContain(
            "pr: 'https://github.com/turbophp/doccum/pull/9101' has mergeSha '".str_repeat('d', 40)."' but it does not appear in its mergedIn run 'run/0001''s merges."
        );
    } finally {
        removeMergesFixture($dir);
    }

    // Restore: the entry is back, sound again.
    $dir = makeSmallMergesFixture([mergesFixtureSoundEntry()], str_repeat('d', 40));
    try {
        expect(LedgerValidator::validate($dir, mergesFixtureSpecPath()))->toBe([]);
    } finally {
        removeMergesFixture($dir);
    }
});

// -- Rule 2: Run.commit is defined as the last merges entry's mergeSha ----

it('defines Run.commit as the last merges entry\'s mergeSha', function () {
    $dir = makeSmallMergesFixture([mergesFixtureSoundEntry()], str_repeat('d', 40));
    try {
        expect(LedgerValidator::validate($dir, mergesFixtureSpecPath()))->toBe([]);
    } finally {
        removeMergesFixture($dir);
    }

    // Mutation-check: point commit at a DIFFERENT (but still well-formed)
    // sha than the one merges actually ends on.
    $dir = makeSmallMergesFixture([mergesFixtureSoundEntry()], str_repeat('e', 40));
    try {
        $errors = LedgerValidator::validate($dir, mergesFixtureSpecPath());
        expect($errors)->toContain(
            "run: 'run/0001'.commit = '".str_repeat('e', 40)."' but its last merges entry's mergeSha is '".str_repeat('d', 40)."'."
        );
    } finally {
        removeMergesFixture($dir);
    }

    // Restore: commit matches the last entry again.
    $dir = makeSmallMergesFixture([mergesFixtureSoundEntry()], str_repeat('d', 40));
    try {
        expect(LedgerValidator::validate($dir, mergesFixtureSpecPath()))->toBe([]);
    } finally {
        removeMergesFixture($dir);
    }
});

/**
 * A fixture with enough filler runs (0000..0020, all but the last
 * "aborted" so they need no touched items) to put the run under test past
 * MERGES_OUTCOME_RULE_EFFECTIVE_AFTER_RUN, for the rule that only binds
 * going forward from it -- the same shape as MutationNodeRulesTest.php's
 * makeRunThresholdMutationFixture().
 *
 * @return string the fixture directory's path
 */
function makeThresholdMergesFixture(?string $testsConclusion, ?string $ledgerConclusion, string $itemId = 'item/merges-threshold-widget', ?int $lastRunNumber = null): string
{
    $dir = sys_get_temp_dir().'/ledger-merges-fixture-'.bin2hex(random_bytes(8));
    mkdir($dir.'/runs', 0777, true);

    copy(base_path('docs/ledger/context.jsonld'), $dir.'/context.jsonld');

    $prUrl = 'https://github.com/turbophp/doccum/pull/9102';
    $lastRunNumber ??= MERGES_OUTCOME_RULE_EFFECTIVE_AFTER_RUN_UNDER_TEST + 1;

    $runsById = [];
    for ($n = 0; $n <= $lastRunNumber; $n++) {
        $start = (new DateTimeImmutable('2030-01-01T00:00:00Z'))->modify(sprintf('+%d hours', $n * 2));
        $end = $start->modify('+1 hour');

        if ($n === 0) {
            $outcome = 'completed';
            $touched = [];
        } elseif ($n === $lastRunNumber) {
            $outcome = 'completed';
            $touched = [$itemId];
        } else {
            $outcome = 'aborted';
            $touched = [];
        }

        $runsById[$n] = [
            '@context' => '../context.jsonld',
            '@id' => sprintf('run/%04d', $n),
            '@type' => 'Run',
            'identifier' => $n,
            'agent' => 'https://claude.ai/code/session_fixture',
            'startTime' => $start->format('Y-m-d\TH:i:s\Z'),
            'endTime' => $end->format('Y-m-d\TH:i:s\Z'),
            'outcome' => $outcome,
            'touched' => $touched,
            'commit' => str_repeat(dechex($n % 16), 40),
            'description' => sprintf('Fixture filler run %d.', $n),
            'merges' => [],
        ];
    }

    $mergeSha = str_repeat('d', 40);
    $lastRunId = sprintf('run/%04d', $lastRunNumber);
    $lastRun = $runsById[$lastRunNumber];
    $lastRun['merges'] = [[
        'mergeSha' => $mergeSha,
        'pullRequest' => $prUrl,
        'mergedAt' => $lastRun['endTime'],
        // Clause 4 (issue #188): a conclusion recorded with no run to point
        // at is its own error now, so a non-null conclusion here carries a
        // run URL, the same as every real merge entry does -- null only
        // when the caller actually wants no conclusion at all.
        'testsRun' => $testsConclusion === null ? null : 'https://github.com/turbophp/doccum/actions/runs/3',
        'testsConclusion' => $testsConclusion,
        'ledgerRun' => $ledgerConclusion === null ? null : 'https://github.com/turbophp/doccum/actions/runs/4',
        'ledgerConclusion' => $ledgerConclusion,
    ]];
    $lastRun['commit'] = $mergeSha;
    $runsById[$lastRunNumber] = $lastRun;

    foreach ($runsById as $n => $run) {
        writeMergesFixtureFile($dir.sprintf('/runs/%04d.jsonld', $n), $run);
    }

    $pullRequest = [
        '@id' => $prUrl,
        '@type' => 'PullRequest',
        'identifier' => 9102,
        'name' => 'Fixture PR past the threshold run',
        'dateCreated' => $lastRun['startTime'],
        'state' => 'merged',
        'headSha' => str_repeat('c', 40),
        'mergeSha' => $mergeSha,
        'mergedAt' => $lastRun['endTime'],
        'run' => $lastRunId,
        'mergedIn' => $lastRunId,
        'implements' => [$itemId],
    ];

    $ledger = [
        '@context' => 'context.jsonld',
        '@id' => 'ledger',
        '@type' => 'Ledger',
        'version' => '1',
        'dateModified' => $lastRun['endTime'],
        'latestRun' => $lastRunId,
        'about' => [
            '@id' => 'https://example.test/fixture',
            '@type' => 'SoftwareApplication',
            'name' => 'fixture',
            'version' => '0.0.0',
        ],
        'items' => [
            [
                '@id' => $itemId,
                '@type' => 'Action',
                'order' => 1,
                'name' => 'Fixture widget past the threshold run',
                'isBasedOn' => ['spec:13-infrastructure'],
                'size' => 'S',
                'release' => 'v1.0.0',
                'dependsOn' => [],
                'doneWhen' => 'Fixture only.',
                'actionStatus' => 'CompletedActionStatus',
                'startTime' => $lastRun['startTime'],
                'endTime' => $lastRun['endTime'],
                'result' => [$prUrl],
            ],
        ],
        'pullRequests' => [$pullRequest],
        'decisions' => [],
        'mutations' => [],
    ];
    writeMergesFixtureFile($dir.'/ledger.jsonld', $ledger);

    return $dir;
}

// -- Rule 3: a completed run's last merge must close both workflows green -

it('requires a completed run past the effective threshold to have both conclusions success on its last merge', function () {
    $dir = makeThresholdMergesFixture(testsConclusion: 'success', ledgerConclusion: 'success');
    try {
        expect(LedgerValidator::validate($dir, mergesFixtureSpecPath()))->toBe([]);
    } finally {
        removeMergesFixture($dir);
    }

    $lastRunId = sprintf('run/%04d', MERGES_OUTCOME_RULE_EFFECTIVE_AFTER_RUN_UNDER_TEST + 1);
    $mergeSha = str_repeat('d', 40);

    // Mutation-check: tests failed on the closing merge, watch the rule
    // catch a run claiming completed anyway.
    $dir = makeThresholdMergesFixture(testsConclusion: 'failure', ledgerConclusion: 'success');
    try {
        $errors = LedgerValidator::validate($dir, mergesFixtureSpecPath());
        expect($errors)->toContain(
            "run: '$lastRunId' has outcome completed but its last merge ('$mergeSha') did not close green".
            " (testsConclusion = 'failure', ledgerConclusion = 'success')."
        );
    } finally {
        removeMergesFixture($dir);
    }

    // Same rule, the other workflow: a run whose ledger check never
    // completed (cancelled) must be caught exactly like a failure -- the
    // enum accepting `cancelled` does not mean this rule tolerates it.
    $dir = makeThresholdMergesFixture(testsConclusion: 'success', ledgerConclusion: 'cancelled');
    try {
        $errors = LedgerValidator::validate($dir, mergesFixtureSpecPath());
        expect($errors)->toContain(
            "run: '$lastRunId' has outcome completed but its last merge ('$mergeSha') did not close green".
            " (testsConclusion = 'success', ledgerConclusion = 'cancelled')."
        );
    } finally {
        removeMergesFixture($dir);
    }

    // Restore: both green again.
    $dir = makeThresholdMergesFixture(testsConclusion: 'success', ledgerConclusion: 'success');
    try {
        expect(LedgerValidator::validate($dir, mergesFixtureSpecPath()))->toBe([]);
    } finally {
        removeMergesFixture($dir);
    }
});

it('does not apply the outcome rule AT the effective threshold run, where real history violates it', function () {
    // run/0000 and run/0008 on the real ledger each close on a red last
    // merge (see MERGES_OUTCOME_RULE_EFFECTIVE_AFTER_RUN's docblock) -- this
    // reproduces the same shape at the boundary run number itself (`>`, not
    // `>=`), to prove the gate rather than merely describe it.
    $dir = makeThresholdMergesFixture(
        testsConclusion: 'failure',
        ledgerConclusion: 'success',
        lastRunNumber: MERGES_OUTCOME_RULE_EFFECTIVE_AFTER_RUN_UNDER_TEST,
    );
    try {
        expect(LedgerValidator::validate($dir, mergesFixtureSpecPath()))->toBe([]);
    } finally {
        removeMergesFixture($dir);
    }
});

/**
 * The shas the ledger actually records for its newest run, oldest first.
 *
 * Derived from the real ledger rather than a fixture on purpose: the check
 * under test compares the ledger against an EXTERNAL list, so feeding it a
 * list built from the same fixture it validates would prove nothing about
 * the comparison.
 */
function recordedShasOfNewestRun(): array
{
    $files = glob(base_path('docs/ledger/runs/*.jsonld'));
    sort($files);
    $run = json_decode((string) file_get_contents((string) end($files)), true);

    return array_map(
        static fn (array $entry): string => (string) $entry['mergeSha'],
        array_values(array_filter($run['merges'] ?? [], 'is_array')),
    );
}

it('reports a commit on main that no run records, when a later commit is recorded', function () {
    $shas = recordedShasOfNewestRun();

    // Fewer than two recorded merges and "interior" has no meaning; the
    // newest run always has more, but say so rather than assert nothing.
    expect(count($shas))->toBeGreaterThan(1);

    $hole = str_repeat('a', 40);

    // Guard present: every sha the external list names is recorded.
    expect(LedgerValidator::validate(base_path('docs/ledger'), mergesFixtureSpecPath(), $shas))->toBe([]);

    // An unrecorded commit with recorded ones AFTER it is a hole somebody
    // left -- the ledger-only merge nobody wrote down, which is the exact
    // shape of both times main has gone red.
    $withHole = $shas;
    array_splice($withHole, 1, 0, [$hole]);

    $errors = LedgerValidator::validate(base_path('docs/ledger'), mergesFixtureSpecPath(), $withHole);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain($hole)
        ->toContain('a LATER commit does');
});

it('does not report a commit newer than everything the ledger records', function () {
    // A merge commit cannot appear in the ledger it contains: its sha does
    // not exist until the merge happens. Requiring it would make `validate
    // ledger` red on main from the instant any PR merges, and red again
    // after the very ledger pass that recorded the previous merges -- a
    // deadlock, not a strict rule. Such a commit becomes interior, and so
    // caught by the test above, as soon as a later merge is recorded.
    $shas = recordedShasOfNewestRun();
    $freshMerge = str_repeat('b', 40);

    $errors = LedgerValidator::validate(
        base_path('docs/ledger'),
        mergesFixtureSpecPath(),
        array_merge($shas, [$freshMerge]),
    );

    expect($errors)->toBe([]);
});

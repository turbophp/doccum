<?php

declare(strict_types=1);

use App\Support\LedgerValidator;

/*
|--------------------------------------------------------------------------
| Mutation node rules (item/ledger-mutation-nodes, issue #150)
|--------------------------------------------------------------------------
|
| LedgerIntegrityTest.php asks LedgerValidator to validate the real, current
| docs/ledger and asserts each invariant holds. It cannot exercise "what if
| this were broken" without breaking the real ledger, so the three new rules
| this item adds get their own small, disposable fixture ledgers here:
| enough structure to be sound on its own (verified against the FULL error
| list, not just a filtered one), then mutated one field at a time to prove
| each rule actually fires, then repaired to prove it stops firing.
|
| Two fixture shapes:
|   - a "small" one (runs/0000-0001) for the two rules that apply at any
|     run number: Mutation.check required on a negative verdict, and an
|     un-superseded positive/void Mutation blocking its item's Completion.
|   - a "run/0019" one (runs/0000-0019) for the rule gated behind
|     LedgerValidator::MUTATION_RULES_EFFECTIVE_AFTER_RUN (18): a Completed
|     spec:10 item needs a negative Mutation. The constant is private, so
|     its value (18) is asserted against directly here rather than
|     referenced; a future change to it will fail these tests loudly rather
|     than silently stop proving anything.
|
| Both fixtures carry a Run.merges entry for their PullRequest, because
| item/ledger-main-push-record (issue #172) made that required (rule 1: a
| merged PR's mergeSha must be in its mergedIn run's merges) -- Run.merges'
| own rules, including the one this file used to cover here (a completed
| run's merged PRs needing what was PullRequest.mainConclusion, now
| Run.merges[].testsConclusion/ledgerConclusion), are proven in
| tests/Feature/Ledger/MainPushMergesRulesTest.php instead, since
| mainConclusion/mainRunUrl no longer exist on PullRequest at all.
|
| docs/ is dockerignored (see CLAUDE.md), so the design spec these fixtures
| cite via isBasedOn (spec:10-user-facing-surface) may not exist in a
| --no-dev install; these tests skip cleanly in that case, same as
| LedgerIntegrityTest.php.
*/

const MUTATION_RULES_EFFECTIVE_AFTER_RUN_UNDER_TEST = 18;

beforeEach(function () {
    if (! is_dir(base_path('docs/ledger'))) {
        $this->markTestSkipped('docs/ is absent -- dockerignored, so it does not exist inside the built image.');
    }
});

/** Byte-for-byte the same rule LedgerValidator::canonicalize() applies, so a hand-built fixture starts with zero 'canonical:' noise. */
function canonicalizeFixtureJson(array $data): string
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

function writeMutationFixtureFile(string $path, array $data): void
{
    file_put_contents($path, canonicalizeFixtureJson($data));
}

/** @return string the fixture directory's path */
function makeSmallMutationFixture(array $mutations, string $itemId = 'item/small-widget'): string
{
    $dir = sys_get_temp_dir().'/ledger-fixture-'.bin2hex(random_bytes(8));
    mkdir($dir.'/runs', 0777, true);

    copy(base_path('docs/ledger/context.jsonld'), $dir.'/context.jsonld');

    $prUrl = 'https://github.com/turbophp/doccum/pull/9001';

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
        'tests' => 1,
        'assertions' => 1,
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
        'commit' => str_repeat('d', 40),
        'tests' => 1,
        'assertions' => 1,
        'description' => 'Fixture run that completes the item under test.',
        // item/ledger-main-push-record (issue #172): a merged PR's mergeSha
        // must be in its mergedIn run's merges, and Run.commit is the last
        // entry's mergeSha -- both satisfied by this one entry.
        'merges' => [[
            'mergeSha' => str_repeat('d', 40),
            'pullRequest' => $prUrl,
            'mergedAt' => '2030-01-01T02:30:00Z',
            'testsRun' => null,
            'testsConclusion' => 'success',
            'ledgerRun' => null,
            'ledgerConclusion' => 'success',
        ]],
    ];
    writeMutationFixtureFile($dir.'/runs/0000.jsonld', $run0);
    writeMutationFixtureFile($dir.'/runs/0001.jsonld', $run1);

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
                'isBasedOn' => ['spec:10-user-facing-surface'],
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
                'identifier' => 9001,
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
        'mutations' => $mutations,
    ];
    writeMutationFixtureFile($dir.'/ledger.jsonld', $ledger);

    return $dir;
}

/**
 * $testsConclusion/$testsRun feed the last run's one Run.merges entry (see
 * item/ledger-main-push-record, issue #172) -- this fixture used to put
 * them on the PullRequest node as mainConclusion/mainRunUrl, before those
 * fields moved to Run.merges. Only this file's surviving test (the spec:10
 * negative-Mutation rule) still calls this helper; the rules Run.merges
 * itself introduced are proven in MainPushMergesRulesTest.php instead.
 *
 * @return string the fixture directory's path
 */
function makeRunThresholdMutationFixture(array $mutations, ?string $testsConclusion, string $itemId = 'item/threshold-widget', ?string $testsRun = null): string
{
    $dir = sys_get_temp_dir().'/ledger-fixture-'.bin2hex(random_bytes(8));
    mkdir($dir.'/runs', 0777, true);

    copy(base_path('docs/ledger/context.jsonld'), $dir.'/context.jsonld');

    $prUrl = 'https://github.com/turbophp/doccum/pull/9002';
    $lastRunNumber = MUTATION_RULES_EFFECTIVE_AFTER_RUN_UNDER_TEST + 1; // run/0019

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
            // 'aborted' carries no "non-empty touched" requirement, so these
            // filler runs need no fixture items of their own.
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
            'tests' => 1,
            'assertions' => 1,
            'description' => sprintf('Fixture filler run %d.', $n),
            'merges' => [],
        ];
    }

    $lastRunId = sprintf('run/%04d', $lastRunNumber);
    $lastRun = $runsById[$lastRunNumber];
    $mergeSha = str_repeat('d', 40);

    $pullRequest = [
        '@id' => $prUrl,
        '@type' => 'PullRequest',
        'identifier' => 9002,
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

    // item/ledger-main-push-record (issue #172): a merged PR's mergeSha
    // must be in its mergedIn run's merges, and Run.commit is the last
    // entry's mergeSha -- both satisfied by this one entry, which also
    // carries what used to be PullRequest.mainConclusion/mainRunUrl.
    $lastRun['merges'] = [[
        'mergeSha' => $mergeSha,
        'pullRequest' => $prUrl,
        'mergedAt' => $lastRun['endTime'],
        'testsRun' => $testsRun,
        'testsConclusion' => $testsConclusion,
        'ledgerRun' => null,
        'ledgerConclusion' => 'success',
    ]];
    $lastRun['commit'] = $mergeSha;
    $runsById[$lastRunNumber] = $lastRun;

    foreach ($runsById as $n => $run) {
        writeMutationFixtureFile($dir.sprintf('/runs/%04d.jsonld', $n), $run);
    }

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
                'isBasedOn' => ['spec:10-user-facing-surface'],
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
        'mutations' => $mutations,
    ];
    writeMutationFixtureFile($dir.'/ledger.jsonld', $ledger);

    return $dir;
}

function removeMutationFixture(string $dir): void
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

function specPathForMutationTests(): string
{
    return base_path('docs/superpowers/specs/2026-09-15-doccum-design.md');
}

// -- Rule: check is required (non-empty) exactly on a negative verdict ----

it('requires a non-empty check on a negative-verdict Mutation', function () {
    $negativeWithCheck = [
        '@id' => 'mutation/0001',
        '@type' => 'Mutation',
        'implements' => ['item/small-widget'],
        'pullRequest' => 'https://github.com/turbophp/doccum/pull/9001',
        'run' => 'run/0001',
        'headSha' => str_repeat('e', 40),
        'mutant' => 'Fixture mutant.',
        'check' => 'FixtureTest > it proves the fixture',
        'verdict' => 'negative',
        'supersedes' => null,
    ];

    $dir = makeSmallMutationFixture([$negativeWithCheck]);
    try {
        expect(LedgerValidator::validate($dir, specPathForMutationTests()))->toBe([]);
    } finally {
        removeMutationFixture($dir);
    }

    // Mutation-check: delete the check, watch the rule catch it.
    $negativeWithoutCheck = [...$negativeWithCheck, 'check' => null];
    $dir = makeSmallMutationFixture([$negativeWithoutCheck]);
    try {
        $errors = LedgerValidator::validate($dir, specPathForMutationTests());
        expect($errors)->toContain("mutation: 'mutation/0001' has verdict negative but no non-empty check -- a failure that names no assertion is not evidence.");
    } finally {
        removeMutationFixture($dir);
    }

    // Restore: a non-empty check on the same node is sound again.
    $dir = makeSmallMutationFixture([$negativeWithCheck]);
    try {
        expect(LedgerValidator::validate($dir, specPathForMutationTests()))->toBe([]);
    } finally {
        removeMutationFixture($dir);
    }
});

// -- Rule: an un-superseded positive/void Mutation blocks Completion -------

it('blocks a Completed item while a positive or void Mutation implementing it is not superseded', function () {
    $void = [
        '@id' => 'mutation/0001',
        '@type' => 'Mutation',
        'implements' => ['item/small-widget'],
        'pullRequest' => 'https://github.com/turbophp/doccum/pull/9001',
        'run' => 'run/0001',
        'headSha' => str_repeat('e', 40),
        'mutant' => 'Fixture mutant.',
        'check' => null,
        'verdict' => 'void',
        'supersedes' => null,
    ];

    // Baseline with no Mutation at all: the item is Completed in run/0001
    // (<= the effective-after threshold), so it is grandfathered out of
    // needing evidence, and there is nothing here to be un-superseded.
    $dir = makeSmallMutationFixture([]);
    try {
        expect(LedgerValidator::validate($dir, specPathForMutationTests()))->toBe([]);
    } finally {
        removeMutationFixture($dir);
    }

    // Mutation-check: an un-superseded void Mutation implementing the
    // Completed item must block it.
    $dir = makeSmallMutationFixture([$void]);
    try {
        $errors = LedgerValidator::validate($dir, specPathForMutationTests());
        expect($errors)->toContain("status: Action 'item/small-widget' is Completed but Mutation 'mutation/0001' (verdict void) implements it and is not yet superseded.");
    } finally {
        removeMutationFixture($dir);
    }

    // Restore: a later negative Mutation superseding it clears the block.
    $negative = [
        '@id' => 'mutation/0002',
        '@type' => 'Mutation',
        'implements' => ['item/small-widget'],
        'pullRequest' => 'https://github.com/turbophp/doccum/pull/9001',
        'run' => 'run/0001',
        'headSha' => str_repeat('f', 40),
        'mutant' => 'Fixture mutant, re-run.',
        'check' => 'FixtureTest > it proves the fixture, second time',
        'verdict' => 'negative',
        'supersedes' => 'mutation/0001',
    ];
    $dir = makeSmallMutationFixture([$void, $negative]);
    try {
        expect(LedgerValidator::validate($dir, specPathForMutationTests()))->toBe([]);
    } finally {
        removeMutationFixture($dir);
    }
});

// -- Rule: a Completed spec:10 item needs a negative Mutation (run > 18) --

it('requires a negative Mutation for a Completed spec:10 item once its run is past the effective threshold', function () {
    $negative = [
        '@id' => 'mutation/0001',
        '@type' => 'Mutation',
        'implements' => ['item/threshold-widget'],
        'pullRequest' => 'https://github.com/turbophp/doccum/pull/9002',
        'run' => sprintf('run/%04d', MUTATION_RULES_EFFECTIVE_AFTER_RUN_UNDER_TEST + 1),
        'headSha' => str_repeat('e', 40),
        'mutant' => 'Fixture mutant.',
        'check' => 'FixtureTest > it proves the fixture',
        'verdict' => 'negative',
        'supersedes' => null,
    ];

    $dir = makeRunThresholdMutationFixture([$negative], testsConclusion: 'success');
    try {
        expect(LedgerValidator::validate($dir, specPathForMutationTests()))->toBe([]);
    } finally {
        removeMutationFixture($dir);
    }

    // Mutation-check: delete the only Mutation node, watch the rule catch it.
    $dir = makeRunThresholdMutationFixture([], testsConclusion: 'success');
    try {
        $errors = LedgerValidator::validate($dir, specPathForMutationTests());
        expect($errors)->toContain("status: Action 'item/threshold-widget' is Completed and based on spec:10, but no negative Mutation with a non-empty check implements it.");
    } finally {
        removeMutationFixture($dir);
    }

    // A positive verdict does not count as evidence either, even with a check.
    $positive = [...$negative, 'verdict' => 'positive', 'supersedes' => null];
    $dir = makeRunThresholdMutationFixture([$positive], testsConclusion: 'success');
    try {
        $errors = LedgerValidator::validate($dir, specPathForMutationTests());
        expect($errors)->toContain("status: Action 'item/threshold-widget' is Completed and based on spec:10, but no negative Mutation with a non-empty check implements it.");
    } finally {
        removeMutationFixture($dir);
    }

    // Restore: the negative Mutation with its check present is sound again.
    $dir = makeRunThresholdMutationFixture([$negative], testsConclusion: 'success');
    try {
        expect(LedgerValidator::validate($dir, specPathForMutationTests()))->toBe([]);
    } finally {
        removeMutationFixture($dir);
    }
});

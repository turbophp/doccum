<?php

declare(strict_types=1);

use App\Support\LedgerValidator;

/*
|--------------------------------------------------------------------------
| Duplicate @id rule (issue #185)
|--------------------------------------------------------------------------
|
| LedgerValidator::idErrors() used to open with what looked like a
| duplicate-@id guard: it iterated $nodes and tracked ids it had already
| seen. It could never fire, because $nodes is a PHP array keyed by @id, so
| its keys are unique by construction -- the duplicate had already been lost
| one step earlier, in collectAndCheckStructure(), where a second node
| sharing an @id silently overwrote the first at $nodes[$id] = [...]. Two
| items were duplicated in docs/ledger/ledger.jsonld on main, undetected,
| for exactly this reason.
|
| This file proves the rule that replaces the dead one, in the style of
| MutationNodeRulesTest.php: a small disposable fixture ledger, verified
| sound against the FULL error list, then one node duplicated to show the
| rule fires, then repaired to show it stops.
|
| It is a sibling file to MutationNodeRulesTest.php rather than an addition
| to it: that file's own docblock scopes it to the Mutation vocabulary
| (item/ledger-mutation-nodes, issue #150), and this rule has nothing to do
| with Mutation nodes -- it is a structural check that applies to every node
| type collectAndCheckStructure() walks (items, pullRequests, decisions,
| mutations, runs, even the ledger and about nodes). Filing it there would
| either misfile it under a heading it does not belong to, or force an
| unrelated rewrite of that file's scope docblock; a new file keeps this
| rule's own issue in a file of its own, the same way MutationNodeRulesTest
| keeps issue #150's.
|
| docs/ is dockerignored (see CLAUDE.md), so this test skips cleanly when
| docs/ledger is absent, same as LedgerIntegrityTest.php and
| MutationNodeRulesTest.php.
*/

beforeEach(function () {
    if (! is_dir(base_path('docs/ledger'))) {
        $this->markTestSkipped('docs/ is absent -- dockerignored, so it does not exist inside the built image.');
    }
});

/** Byte-for-byte the same rule LedgerValidator::canonicalize() applies, so a hand-built fixture starts with zero 'canonical:' noise. */
function canonicalizeDuplicateIdFixtureJson(array $data): string
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

function writeDuplicateIdFixtureFile(string $path, array $data): void
{
    file_put_contents($path, canonicalizeDuplicateIdFixtureJson($data));
}

/**
 * @param  list<array<string, mixed>>  $items
 * @return string the fixture directory's path
 */
function makeDuplicateIdFixture(array $items): string
{
    $dir = sys_get_temp_dir().'/ledger-dup-id-fixture-'.bin2hex(random_bytes(8));
    mkdir($dir.'/runs', 0777, true);

    copy(base_path('docs/ledger/context.jsonld'), $dir.'/context.jsonld');
    // vocab.md travels with context.jsonld: the `vocab` rule reads the
    // ledger DIRECTORY, so a fixture carrying one without the other is
    // not a ledger directory and fails for a reason the live tree does
    // not have (item/vocab-resolves).
    copy(base_path('docs/ledger/vocab.md'), $dir.'/vocab.md');

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
    writeDuplicateIdFixtureFile($dir.'/runs/0000.jsonld', $run0);

    $ledger = [
        '@context' => 'context.jsonld',
        '@id' => 'ledger',
        '@type' => 'Ledger',
        'version' => '1',
        'dateModified' => '2030-01-01T01:00:00Z',
        'latestRun' => 'run/0000',
        'about' => [
            '@id' => 'https://example.test/fixture',
            '@type' => 'SoftwareApplication',
            'name' => 'fixture',
            'version' => '0.0.0',
        ],
        'items' => $items,
        'pullRequests' => [],
        'decisions' => [],
        'mutations' => [],
    ];
    writeDuplicateIdFixtureFile($dir.'/ledger.jsonld', $ledger);

    return $dir;
}

function removeDuplicateIdFixture(string $dir): void
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

function duplicateIdSpecPath(): string
{
    return base_path('docs/superpowers/specs/2026-09-15-doccum-design.md');
}

/** @return array<string, mixed> */
function duplicateIdFixtureWidget(int $order): array
{
    return [
        '@id' => 'item/dup-widget',
        '@type' => 'Action',
        'order' => $order,
        'name' => 'Fixture widget',
        'isBasedOn' => ['spec:10-user-facing-surface'],
        'size' => 'S',
        'release' => 'v1.0.0',
        'dependsOn' => [],
        'doneWhen' => 'Fixture only.',
        'actionStatus' => 'PotentialActionStatus',
        'startTime' => null,
        'endTime' => null,
        'result' => [],
    ];
}

it('flags a node that redefines an id already used by an earlier node', function () {
    $dir = makeDuplicateIdFixture([duplicateIdFixtureWidget(1)]);
    try {
        expect(LedgerValidator::validate($dir, duplicateIdSpecPath()))->toBe([]);
    } finally {
        removeDuplicateIdFixture($dir);
    }

    // Mutation-check: a second item node reusing the same @id must be
    // caught here, before $nodes[$id] silently overwrites the first copy
    // and the duplicate becomes invisible to every later check -- exactly
    // what happened, undetected, to two real items in ledger.jsonld.
    $dir = makeDuplicateIdFixture([
        duplicateIdFixtureWidget(1),
        duplicateIdFixtureWidget(2),
    ]);
    try {
        $errors = LedgerValidator::validate($dir, duplicateIdSpecPath());
        expect($errors)->toContain(
            "id: 'item/dup-widget' is defined by more than one node -- JSON-LD merges nodes sharing an @id, so this is not two items to any consumer, it is one item whose fields came from whichever copy parsed last."
        );
    } finally {
        removeDuplicateIdFixture($dir);
    }

    // Restore: a single node with that id, sound again.
    $dir = makeDuplicateIdFixture([duplicateIdFixtureWidget(1)]);
    try {
        expect(LedgerValidator::validate($dir, duplicateIdSpecPath()))->toBe([]);
    } finally {
        removeDuplicateIdFixture($dir);
    }
});

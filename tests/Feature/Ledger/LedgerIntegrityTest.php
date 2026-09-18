<?php

declare(strict_types=1);

use App\Support\LedgerValidator;

/*
|--------------------------------------------------------------------------
| Ledger integrity
|--------------------------------------------------------------------------
|
| An hourly loop rewrites docs/ledger/*.jsonld every iteration; nothing else
| checks that the rewrite kept the graph coherent. This is that check.
|
| `docs/` is dockerignored (see CLAUDE.md), so it does not exist inside the
| built image or in a --no-dev install -- every test here skips cleanly
| when docs/ledger is absent instead of failing.
|
| All the actual rules live in App\Support\LedgerValidator, which is
| framework-free so .github/scripts/validate-ledger.php can run the same
| checks in CI before `composer install`. This file only asks it for the
| errors and groups them by the category tag each error starts with, so a
| failure here names the invariant that broke rather than just "something
| is wrong with the ledger".
*/

function ledgerDirectoryPath(): string
{
    return base_path('docs/ledger');
}

function ledgerSpecPath(): string
{
    return base_path('docs/superpowers/specs/2026-09-15-doccum-design.md');
}

/**
 * @return list<string>
 */
function ledgerValidationErrors(): array
{
    static $errors = null;

    // Memoised: every it() below asks for the same run, filtered by prefix.
    if ($errors === null) {
        $errors = LedgerValidator::validate(ledgerDirectoryPath(), ledgerSpecPath());
    }

    return $errors;
}

/**
 * @return list<string>
 */
function ledgerErrorsTagged(string $prefix): array
{
    return array_values(array_filter(
        ledgerValidationErrors(),
        fn (string $error): bool => str_starts_with($error, $prefix),
    ));
}

beforeEach(function () {
    if (! is_dir(ledgerDirectoryPath())) {
        $this->markTestSkipped('docs/ledger is absent -- dockerignored, so it does not exist inside the built image.');
    }
});

it('loads ledger.jsonld and every run file with no fatal errors', function () {
    expect(ledgerErrorsTagged('fatal:'))->toBe([]);
});

it('closes the vocabulary: every node has one valid @type and only keys context.jsonld allows for it', function () {
    expect(ledgerErrorsTagged('structural:'))->toBe([]);
});

it('every @id matches its type\'s pattern and is unique across the ledger and its runs', function () {
    expect(ledgerErrorsTagged('id:'))->toBe([]);
});

it('every dependsOn, implements, affects, touched, supersedes, run, mergedIn, latestRun and result reference resolves to a node of the expected type', function () {
    expect(ledgerErrorsTagged('ref:'))->toBe([]);
});

it('enumerated fields -- size, release, actionStatus, state, outcome, shas -- only carry allowed values', function () {
    expect(ledgerErrorsTagged('enum:'))->toBe([]);
});

it('order values are unique and positive, and items, pullRequests and decisions are sorted', function () {
    expect(ledgerErrorsTagged('order:'))->toBe([]);
});

it('ledger.jsonld and every run file are canonically serialized, so hourly rewrites stay a minimal diff', function () {
    expect(ledgerErrorsTagged('canonical:'))->toBe([]);
});

it('dependsOn is acyclic', function () {
    expect(ledgerErrorsTagged('graph:'))->toBe([]);
});

it('actionStatus timestamps and the Completed rule hold, including that item/tag-v1-0-0 completing implies every v1.0.0 item is Completed', function () {
    expect(ledgerErrorsTagged('status:'))->toBe([]);
});

it('a merged pull request has its merge fields, a non-merged one does not, and every implemented item was touched by its run', function () {
    expect(ledgerErrorsTagged('pr:'))->toBe([]);
});

it('a decision only supersedes an earlier decision', function () {
    expect(ledgerErrorsTagged('decision:'))->toBe([]);
});

it('run files are numbered contiguously with consistent dates, and a completed run (after the baseline) names what it touched', function () {
    expect(ledgerErrorsTagged('run:'))->toBe([]);
});

it('every isBasedOn anchor matches a real heading in the design spec', function () {
    expect(ledgerErrorsTagged('spec-anchor:'))->toBe([]);
});

it('has no main-push history to check without a git fact, and this call supplies none', function () {
    // item/ledger-main-push-record (issue #172): LedgerValidator stays
    // framework-free and file-only, so it never shells out to git itself --
    // ledgerValidationErrors() above calls validate() with no third
    // argument, exactly as a caller with no git fact must. Only the CLI
    // wrapper (.github/scripts/validate-ledger.php) supplies one, from
    // `git log --first-parent`, and MainPushMergesRulesTest.php proves the
    // rule fires once a fact IS supplied. This assertion is never expected
    // to fail; it documents that this caller's own contract is to skip it.
    expect(ledgerErrorsTagged('history:'))->toBe([]);
});

it('is fully sound end to end', function () {
    // A safety net: catches any error LedgerValidator might one day emit
    // under a category tag not asserted individually above.
    expect(ledgerValidationErrors())->toBe([]);
});

/**
 * A disposable copy of the real ledger, so a fixture can carry a key the
 * vocabulary forbids without touching docs/ledger itself.
 */
function ledgerCopyWithExtraRunKey(string $key, mixed $value): string
{
    $dir = sys_get_temp_dir().'/ledger-vocab-'.bin2hex(random_bytes(6));
    mkdir($dir.'/runs', 0777, true);

    copy(ledgerDirectoryPath().'/context.jsonld', $dir.'/context.jsonld');
    copy(ledgerDirectoryPath().'/ledger.jsonld', $dir.'/ledger.jsonld');

    foreach ((array) glob(ledgerDirectoryPath().'/runs/*.jsonld') as $path) {
        copy((string) $path, $dir.'/runs/'.basename((string) $path));
    }

    $runPath = $dir.'/runs/0000.jsonld';
    $run = json_decode((string) file_get_contents($runPath), true);
    $run[$key] = $value;
    file_put_contents($runPath, json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

    return $dir;
}

/**
 * @return list<string>
 */
function structuralErrorsIn(string $dir): array
{
    return array_values(array_filter(
        LedgerValidator::validate($dir, ledgerSpecPath()),
        fn (string $error): bool => str_starts_with($error, 'structural:'),
    ));
}

function removeLedgerCopy(string $dir): void
{
    foreach ((array) glob($dir.'/runs/*.jsonld') as $path) {
        unlink((string) $path);
    }
    rmdir($dir.'/runs');
    foreach ((array) glob($dir.'/*.jsonld') as $path) {
        unlink((string) $path);
    }
    rmdir($dir);
}

it('rejects a run key the vocabulary no longer defines', function () {
    // item/ledger-field-audit deletes Run.tests and Run.assertions, and its
    // doneWhen says a deleted field needs no test of its own because "its
    // absence from ALLOWED_KEYS is the assertion and LedgerIntegrityTest
    // already fails an unknown key".
    //
    // That justification is worth exactly what these two tests make it
    // worth. The vocabulary test above asserts the REAL ledger has no
    // structural errors -- true and useful, and it passes just as happily
    // if the checks are deleted outright, because it cannot tell "no node
    // breaks the vocabulary" from "nothing is checking".
    //
    // Note WHICH branch actually catches this one: the fields were removed
    // from context.jsonld as well, so re-adding `tests` trips the
    // undefined-term check and never reaches the per-type ALLOWED_KEYS
    // check. The next test covers that other branch, because the two fail
    // independently.
    $dir = ledgerCopyWithExtraRunKey('tests', 649);

    try {
        $structural = structuralErrorsIn($dir);

        expect($structural)->toHaveCount(1);
        expect($structural[0])->toContain("has key 'tests'")
            ->toContain('context.jsonld does not define');
    } finally {
        removeLedgerCopy($dir);
    }
});

it('rejects a key the vocabulary defines but does not allow on that type', function () {
    // `verdict` is a real term -- context.jsonld defines it for Mutation --
    // so this reaches the per-type ALLOWED_KEYS check rather than the
    // undefined-term one above. Without this, that branch has no witness at
    // all: every real node satisfies it, so only a node that violates it
    // can prove it fires.
    $dir = ledgerCopyWithExtraRunKey('verdict', 'negative');

    try {
        $structural = structuralErrorsIn($dir);

        expect($structural)->toHaveCount(1);
        expect($structural[0])->toContain("has key 'verdict'")
            ->toContain('not allowed on a Run');
    } finally {
        removeLedgerCopy($dir);
    }
});

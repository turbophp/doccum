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

it('enumerated fields -- size, release, actionStatus, state, outcome, shas, tests and assertions -- only carry allowed values', function () {
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

it('is fully sound end to end', function () {
    // A safety net: catches any error LedgerValidator might one day emit
    // under a category tag not asserted individually above.
    expect(ledgerValidationErrors())->toBe([]);
});

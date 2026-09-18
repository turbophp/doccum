<?php

declare(strict_types=1);

use App\Support\LedgerRuleWitnesses;
use App\Support\LedgerValidator;

/*
|--------------------------------------------------------------------------
| Rule witnesses
|--------------------------------------------------------------------------
|
| item/ledger-rule-witnesses (issue #189). LedgerIntegrityTest.php proves
| the live ledger validates clean; it cannot prove any individual rule is
| load-bearing, because a rule with a dead `$errors[] = ` line and one whose
| line actually fires look identical to an assertion that only ever sees
| clean data (decision/0067). This file is that other half: it drives
| App\Support\LedgerRuleWitnesses::run(), which mutates disposable copies of
| the live ledger and checks that every category LedgerValidator::CODES
| declares is either proven by a witness or sits in the class's own
| shrinking, size-capped baseline.
|
| Reuses ledgerDirectoryPath()/ledgerSpecPath() from LedgerIntegrityTest.php
| -- both files load into the same global scope, and redeclaring either
| function here would be a fatal "cannot redeclare".
*/

it('LedgerValidator::CODES matches every category tag actually emitted in its own source, in both directions', function () {
    // The docblock sentence this constant replaced was already wrong --
    // see LedgerValidator's own class docblock -- because prose cannot be
    // asserted against. This re-derives the real set straight from the
    // source text on every run, so CODES cannot drift from the code the
    // way the docblock already had.
    $source = (string) file_get_contents(base_path('app/Support/LedgerValidator.php'));

    preg_match_all('/\$errors\[\]\s*=\s*[\'"]([a-z][a-z-]*):/', $source, $matches);
    $emitted = array_values(array_unique($matches[1]));
    sort($emitted);

    $declared = LedgerValidator::CODES;
    sort($declared);

    expect($emitted)->toBe($declared);
});

it('witnesses every code LedgerValidator declares, with the shrinking baseline covering the rest', function () {
    if (! is_dir(ledgerDirectoryPath())) {
        $this->markTestSkipped('docs/ledger is absent -- dockerignored, so it does not exist inside the built image.');
    }

    $result = LedgerRuleWitnesses::run(ledgerDirectoryPath(), ledgerSpecPath());

    expect($result['failures'])->toBe([]);
});

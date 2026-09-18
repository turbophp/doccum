<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| mutation-check.php's regex filter (issue #180)
|--------------------------------------------------------------------------
|
| .github/scripts/mutation-check.php runs each mutations.json entry's named
| test with `php artisan test --filter=<expectFailing>`. --filter is matched
| by PHPUnit/Pest as a REGULAR EXPRESSION against the full test identifier.
| escapeshellarg() only makes a value safe for the shell; it never escaped
| the value for the regex engine downstream of it, so an expectFailing
| containing a metacharacter -- `can()`, on #179 -- silently became a
| different pattern than the test name it names, and matched either the
| wrong test or nothing at all. Both failure modes then surfaced as the same
| "does not pass with the guard in place" message, which is what made #179
| take a round to identify.
|
| mutation-check.php is a top-level CLI script with no autoloader (it runs
| in CI before `composer install`), so it cannot be unit-tested the way
| App\Support\LedgerValidator is in the sibling files in
| tests/Feature/Ledger/ (DuplicateIdRuleTest.php, MutationNodeRulesTest.php).
| It lives in tests/Feature/Guards/ rather than tests/Feature/Ledger/ because
| it has nothing to do with the ledger domain those files cover -- it backs
| the "guards" CI job (.github/workflows/tests.yml) that runs this very
| script. The fix restructures the script so its pure pieces --
| buildFilterPattern() and classifyMutationRun() -- are plain functions this
| file can `require_once` and call directly, with the mutation sweep itself
| guarded so requiring the file never runs it (see the realpath() check at
| the bottom of mutation-check.php).
|
| .github/ is dockerignored (.dockerignore:2), exactly as docs/ is, so this
| file skips when the script is absent -- the same guard the Ledger tests
| carry for the same reason. Without it a missing script would be a fatal
| require_once error rather than a skip, which is a worse way to learn the
| file is not in the image.
*/

beforeEach(function () {
    $script = base_path('.github/scripts/mutation-check.php');

    if (! is_file($script)) {
        $this->markTestSkipped('.github/ is absent -- dockerignored, so it does not exist inside the built image.');
    }

    // base_path() needs the application booted, which has not happened yet
    // at the point this file's top-level code would otherwise run -- see
    // how the Ledger tests defer their own base_path() calls into functions
    // for the same reason. require_once is idempotent, so paying for it on
    // every test in this file is harmless.
    require_once $script;
});

it('escapes a name containing regex metacharacters so it matches only the literal test it names', function () {
    // The real test name from issue #179.
    $name = 'changes what can() answers in the same request once the permission cache is forgotten';

    $escaped = buildFilterPattern($name);
    expect(preg_match('/'.$escaped.'/', $name))->toBe(1);

    // Mutation-check, and the part that proves the bug: fed to the regex
    // engine RAW (no preg_quote()), '()' becomes an empty capture group, so
    // the pattern then demands ' answers' immediately after 'can' -- which
    // the real name, which has 'can() answers', never gives it at that
    // position. Zero tests matched on #179 for exactly this reason.
    expect(preg_match('/'.$name.'/', $name))->toBe(0);
});

it('classifies a run that matched no test differently from one that matched a failing test', function () {
    // Zero <testcase> elements in the JUnit log: --filter matched nothing,
    // regardless of exit code. This is the "manifest/name error" case.
    $noTestMatched = classifyMutationRun(1, 0);

    // A non-zero exit code with real <testcase> elements present: --filter
    // matched a genuine test, and it failed. This is the "broken guard or
    // broken test" case -- a different defect from the one above.
    $testFailed = classifyMutationRun(1, 3);

    expect($noTestMatched)->toBe(MUTATION_RUN_NO_TEST_MATCHED);
    expect($testFailed)->toBe(MUTATION_RUN_TEST_FAILED);

    // The part #180 asks for explicitly: these must not collapse into the
    // same outcome, the way both surfaced as one "does not pass" message
    // before this fix.
    expect($noTestMatched)->not->toBe($testFailed);
});

it('classifies a clean exit with matched testcases as passed, and treats it as distinct from either failure', function () {
    $passed = classifyMutationRun(0, 3);

    expect($passed)->toBe(MUTATION_RUN_PASSED);
    expect($passed)->not->toBe(MUTATION_RUN_NO_TEST_MATCHED);
    expect($passed)->not->toBe(MUTATION_RUN_TEST_FAILED);
});

it('reports a run that produced no JUnit log as unclassifiable, not as a name error', function () {
    // An empty or unparseable log is no evidence at all. Reading it as
    // "matched no test" would blame every entry in the manifest for a name
    // each one spells correctly, the moment --log-junit stopped working --
    // the same misdiagnosis #180 is about, wearing a different hat.
    expect(countJunitTestCases(''))->toBeNull();
    expect(countJunitTestCases('this is not xml'))->toBeNull();

    // A log that DID come back and genuinely recorded nothing is still zero,
    // not null: that is the real "--filter matched no test" signal.
    expect(countJunitTestCases('<?xml version="1.0"?><testsuites></testsuites>'))->toBe(0);

    $unclassifiable = classifyMutationRun(1, null);

    expect($unclassifiable)->toBe(MUTATION_RUN_UNCLASSIFIABLE);
    expect($unclassifiable)->not->toBe(MUTATION_RUN_NO_TEST_MATCHED);
    expect($unclassifiable)->not->toBe(MUTATION_RUN_TEST_FAILED);
});

it('refuses a deleted-guard run that matched no test as proof that the guard is load-bearing', function () {
    // The only outcome that proves a guard load-bearing is a real test that
    // ran and failed. Red because the deletion stopped the file loading is
    // red for an unrelated reason -- accepting it is the "looks like
    // coverage and is not" failure this whole script exists to prevent.
    expect(evaluateMutationRun('some-id', 'some test name', MUTATION_RUN_TEST_FAILED))->toBeNull();

    expect(evaluateMutationRun('some-id', 'some test name', MUTATION_RUN_NO_TEST_MATCHED))
        ->toBeString()
        ->toContain('matched no test at all');

    expect(evaluateMutationRun('some-id', 'some test name', MUTATION_RUN_PASSED))
        ->toBeString()
        ->toContain('STILL PASSES');

    expect(evaluateMutationRun('some-id', 'some test name', MUTATION_RUN_UNCLASSIFIABLE))
        ->toBeString()
        ->toContain('JUnit log');
});

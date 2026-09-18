<?php

declare(strict_types=1);

/**
 * Proves that every guard listed in .github/mutations.json is load-bearing.
 *
 * CLAUDE.md requires it: "Delete the guard, confirm the test fails, restore
 * it. A guard whose test passes without it is worse than none, because it
 * looks protected." Three guards in this codebase have already passed
 * without the code they were guarding, which is why this runs in CI rather
 * than living in someone's memory of the rule.
 *
 * For each mutation it asserts BOTH directions:
 *   1. the named test passes with the guard in place, and
 *   2. it fails with the guard deleted.
 *
 * The first check is not ceremony. A test that fails either way proves
 * nothing about the guard -- it is just broken -- and would otherwise read
 * as a passing mutation check.
 *
 * The source file is always restored, including when a test run dies.
 *
 * --- issue #180 -----------------------------------------------------------
 *
 * `--filter` is matched by PHPUnit/Pest as a REGULAR EXPRESSION against the
 * full test identifier. escapeshellarg() only makes a value safe for the
 * SHELL; it does nothing for the regex engine downstream of it. An
 * `expectFailing` containing a metacharacter -- `can()`, for instance --
 * silently became a different pattern than the test name it was meant to be,
 * and matched either nothing or the wrong thing. Both buildFilterPattern()
 * and classifyMutationRun() below exist so that mistake has exactly one
 * place to happen, and so a test can prove it does not happen there without
 * running a real test suite.
 *
 * This file has no autoloader (it runs before `composer install` in CI), so
 * everything below the guard at the bottom is a plain function: a test may
 * `require_once` this file to get them without triggering the mutation
 * sweep. Only code reached through that guard performs a side effect.
 */

const MUTATION_RUN_PASSED = 'passed';
const MUTATION_RUN_NO_TEST_MATCHED = 'no_test_matched';
const MUTATION_RUN_TEST_FAILED = 'test_failed';

/**
 * Turns an expectFailing value -- a fragment of a test name, never anchored
 * -- into the pattern actually handed to `--filter`.
 *
 * preg_quote() with no delimiter argument escapes every character PCRE
 * treats specially (including '.', which happened to be harmless here by
 * luck) while leaving '/' alone, so the existing entries -- all substring
 * fragments -- keep matching exactly what they matched before. Anchoring
 * with '/^.../$' is deliberately NOT done here: PHPUnit matches --filter
 * against the full "ClassName::test name" identifier, and an anchored
 * pattern would stop matching every one of them at once.
 */
function buildFilterPattern(string $expectFailing): string
{
    return preg_quote($expectFailing);
}

/**
 * Counts <testcase> elements in a JUnit XML report.
 *
 * Zero is the unambiguous signal that --filter matched no test at all --
 * unlike Pest's prose summary, it does not depend on wording that could
 * change out from under this script.
 */
function countJunitTestCases(string $junitXml): int
{
    if (trim($junitXml) === '') {
        return 0;
    }

    $previousSetting = libxml_use_internal_errors(true);

    try {
        $document = simplexml_load_string($junitXml);
    } finally {
        libxml_use_internal_errors($previousSetting);
    }

    if ($document === false) {
        return 0;
    }

    $testCases = $document->xpath('//testcase');

    return is_array($testCases) ? count($testCases) : 0;
}

/**
 * Classifies one filtered test run from evidence alone -- no shelling out,
 * no string-matching Pest's output -- so it can be exercised with canned
 * inputs.
 *
 * A zero testcase count wins regardless of exit code: PHPUnit/Pest exits
 * non-zero when nothing matches a filter, which is otherwise indistinguishable
 * from "matched a test that failed" -- exactly the ambiguity issue #180
 * describes ("does not pass with the guard in place" for either cause).
 */
function classifyMutationRun(int $exitCode, int $testCaseCount): string
{
    if ($testCaseCount === 0) {
        return MUTATION_RUN_NO_TEST_MATCHED;
    }

    return $exitCode === 0 ? MUTATION_RUN_PASSED : MUTATION_RUN_TEST_FAILED;
}

/**
 * Runs `php artisan test` filtered to one pattern and reports the evidence
 * classifyMutationRun() needs: the exit code, and how many <testcase>
 * elements the JUnit log recorded.
 *
 * $pattern is expected to already be a regex-escaped value (buildFilterPattern());
 * escapeshellarg() here is the shell layer only, same as before -- it does
 * not double as regex escaping and never did.
 *
 * @return array{exitCode: int, testCaseCount: int}
 */
function runFilteredTest(string $root, string $pattern): array
{
    $junitPath = tempnam(sys_get_temp_dir(), 'mutation-junit-');

    if ($junitPath === false) {
        throw new RuntimeException('Could not allocate a temp file for the JUnit log.');
    }

    try {
        $command = sprintf(
            'cd %s && php artisan test --filter=%s --log-junit %s 2>&1',
            escapeshellarg($root),
            escapeshellarg($pattern),
            escapeshellarg($junitPath),
        );

        exec($command, $output, $exitCode);

        $junitXml = is_file($junitPath) ? (string) file_get_contents($junitPath) : '';

        return [
            'exitCode' => $exitCode,
            'testCaseCount' => countJunitTestCases($junitXml),
        ];
    } finally {
        if (is_file($junitPath)) {
            unlink($junitPath);
        }
    }
}

/**
 * The message shown when a run did not come back MUTATION_RUN_PASSED,
 * distinguishing "matched nothing" from "matched a real, failing test" --
 * the two defects issue #180 says must not read as the same thing.
 */
function describeUnproductiveRun(string $id, string $filter, string $classification): string
{
    if ($classification === MUTATION_RUN_NO_TEST_MATCHED) {
        return "{$id}: \"{$filter}\" matched no test at all (0 <testcase> entries in the JUnit log) -- check expectFailing against the real test name in .github/mutations.json; this is a manifest/name problem, not a failing test.";
    }

    return "{$id}: \"{$filter}\" matched a test, but it did not pass with the guard in place, so it cannot prove anything about removing it.";
}

/**
 * The mutation sweep itself. Separated from the CLI guard below purely so
 * the side-effecting parts of this script are still easy to find in one
 * place; it is not meant to be called from a test.
 */
function runMutationSweep(string $root): int
{
    $manifestPath = $root.'/.github/mutations.json';

    if (! is_file($manifestPath)) {
        fwrite(STDERR, "No mutation manifest at {$manifestPath}.\n");

        return 1;
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true);

    if (! is_array($manifest) || $manifest === []) {
        fwrite(STDERR, "The mutation manifest is empty or not a JSON array.\n");

        return 1;
    }

    /** @var array<int, string> $failures */
    $failures = [];
    $restore = [];

    // Registered before anything is edited: a fatal error or a killed test
    // run must not leave a guard deleted in the working tree.
    register_shutdown_function(static function () use (&$restore): void {
        foreach ($restore as $path => $original) {
            file_put_contents($path, $original);
        }
    });

    foreach ($manifest as $mutation) {
        $id = (string) ($mutation['id'] ?? '?');
        $relative = (string) ($mutation['file'] ?? '');
        $remove = (string) ($mutation['remove'] ?? '');
        $filter = (string) ($mutation['expectFailing'] ?? '');
        $pattern = buildFilterPattern($filter);
        $path = $root.'/'.$relative;

        printf("=== %s ===\n", $id);
        printf("    %s\n", (string) ($mutation['why'] ?? ''));

        if (! is_file($path)) {
            $failures[] = "{$id}: {$relative} does not exist.";

            continue;
        }

        $original = (string) file_get_contents($path);
        $occurrences = substr_count($original, $remove);

        if ($occurrences !== 1) {
            // Anything other than exactly one match means the manifest has
            // drifted from the code, and a mutation that silently patches
            // nothing would "pass" while proving nothing at all.
            $failures[] = "{$id}: the guard text matches {$occurrences} times in {$relative}; it must match exactly once. Update .github/mutations.json to follow the code.";

            continue;
        }

        $beforeEvidence = runFilteredTest($root, $pattern);
        $beforeClassification = classifyMutationRun($beforeEvidence['exitCode'], $beforeEvidence['testCaseCount']);

        if ($beforeClassification !== MUTATION_RUN_PASSED) {
            $failures[] = describeUnproductiveRun($id, $filter, $beforeClassification);

            continue;
        }

        printf("    guard present  -> \"%s\" passes\n", $filter);

        $restore[$path] = $original;
        file_put_contents($path, str_replace($remove, '', $original));

        $afterEvidence = runFilteredTest($root, $pattern);
        $afterClassification = classifyMutationRun($afterEvidence['exitCode'], $afterEvidence['testCaseCount']);

        file_put_contents($path, $original);
        unset($restore[$path]);

        if ($afterClassification === MUTATION_RUN_PASSED) {
            $failures[] = "{$id}: \"{$filter}\" STILL PASSES with the guard deleted. The guard is not load-bearing, or the test does not exercise it.";

            continue;
        }

        printf("    guard deleted  -> \"%s\" fails, as it must\n\n", $filter);
    }

    if ($failures !== []) {
        fwrite(STDERR, "\nMutation check failed:\n\n");

        foreach ($failures as $failure) {
            fwrite(STDERR, "  - {$failure}\n");
        }

        fwrite(STDERR, "\n");

        return 1;
    }

    printf("All %d guard(s) are load-bearing.\n", count($manifest));

    return 0;
}

// Guards the CLI entry point so a test may `require_once` this file for its
// functions/constants without running the mutation sweep. realpath() on
// argv[0] equals __FILE__ only when THIS file is the script PHP was invoked
// with directly; a `require_once` from inside phpunit/pest's own entry
// script leaves argv[0] pointing at that entry script instead, so the sweep
// never fires there.
if (realpath((string) ($_SERVER['argv'][0] ?? '')) === __FILE__) {
    exit(runMutationSweep(dirname(__DIR__, 2)));
}

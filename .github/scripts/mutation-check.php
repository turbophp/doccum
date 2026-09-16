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
 */
$root = dirname(__DIR__, 2);
$manifestPath = $root.'/.github/mutations.json';

if (! is_file($manifestPath)) {
    fwrite(STDERR, "No mutation manifest at {$manifestPath}.\n");
    exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);

if (! is_array($manifest) || $manifest === []) {
    fwrite(STDERR, "The mutation manifest is empty or not a JSON array.\n");
    exit(1);
}

/** @var array<int, string> $failures */
$failures = [];
$restore = [];

// Registered before anything is edited: a fatal error or a killed test run
// must not leave a guard deleted in the working tree.
register_shutdown_function(static function () use (&$restore): void {
    foreach ($restore as $path => $original) {
        file_put_contents($path, $original);
    }
});

function runTest(string $root, string $filter): bool
{
    $command = sprintf(
        'cd %s && php artisan test --filter=%s 2>&1',
        escapeshellarg($root),
        escapeshellarg($filter),
    );

    exec($command, $output, $exitCode);

    return $exitCode === 0;
}

foreach ($manifest as $mutation) {
    $id = (string) ($mutation['id'] ?? '?');
    $relative = (string) ($mutation['file'] ?? '');
    $remove = (string) ($mutation['remove'] ?? '');
    $filter = (string) ($mutation['expectFailing'] ?? '');
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

    if (! runTest($root, $filter)) {
        $failures[] = "{$id}: \"{$filter}\" does not pass with the guard in place, so it cannot prove anything about removing it.";

        continue;
    }

    printf("    guard present  -> \"%s\" passes\n", $filter);

    $restore[$path] = $original;
    file_put_contents($path, str_replace($remove, '', $original));

    $stillPasses = runTest($root, $filter);

    file_put_contents($path, $original);
    unset($restore[$path]);

    if ($stillPasses) {
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
    exit(1);
}

printf("All %d guard(s) are load-bearing.\n", count($manifest));
exit(0);

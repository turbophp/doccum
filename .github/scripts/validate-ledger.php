<?php

declare(strict_types=1);

/**
 * CI's front door to the ledger rules.
 *
 * Runs before `composer install`, so it cannot autoload anything -- it
 * requires App\Support\LedgerValidator directly by path, which is why that
 * class stays framework-free (see its docblock and CLAUDE.md's "provider
 * seams" table). tests/Feature/Ledger/LedgerIntegrityTest.php requires the
 * same file, so there is exactly one place the rules live.
 *
 * Usage: php .github/scripts/validate-ledger.php [ledger-dir] [spec-path]
 * Exits 0 and prints nothing but a summary when the ledger is sound.
 * Exits 1 and lists every failing invariant otherwise.
 */

$root = dirname(__DIR__, 2);

require $root.'/app/Support/LedgerValidator.php';

$ledgerDir = $argv[1] ?? $root.'/docs/ledger';
$specPath = $argv[2] ?? $root.'/docs/superpowers/specs/2026-09-15-doccum-design.md';

// Only an ABSENT path may skip: docs/ is dockerignored (see CLAUDE.md), so
// inside the built image there is genuinely nothing to validate, and that is
// not a failure of this check.
//
// A path that EXISTS but is not a directory is a different thing entirely --
// it means the caller pointed this script at the wrong thing, and skipping
// silently turns a check named for validation into one that validates
// nothing while reporting success. That is exactly how this check spent its
// whole life green: .github/workflows/ledger.yml passed the ledger FILE
// where the directory was expected, so every run printed "nothing to
// validate" and exited 0. A guard whose absence nobody notices is worse than
// no guard, because it looks like protection.
if (file_exists($ledgerDir) && ! is_dir($ledgerDir)) {
    fwrite(STDERR, "Expected a ledger DIRECTORY but $ledgerDir is a file.\n");
    exit(1);
}

if (! is_dir($ledgerDir)) {
    fwrite(STDOUT, "No ledger at $ledgerDir; nothing to validate.\n");
    exit(0);
}

$errors = App\Support\LedgerValidator::validate($ledgerDir, $specPath);

if ($errors === []) {
    fwrite(STDOUT, "Ledger is sound: $ledgerDir\n");
    exit(0);
}

fwrite(STDERR, sprintf("Ledger validation failed with %d issue%s:\n\n", count($errors), count($errors) === 1 ? '' : 's'));
foreach ($errors as $error) {
    fwrite(STDERR, "  - $error\n");
}
fwrite(STDERR, "\n");

exit(1);

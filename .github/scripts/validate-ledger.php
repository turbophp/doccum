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
 * Usage: php .github/scripts/validate-ledger.php [ledger-dir] [spec-path] [main-sha]
 * Exits 0 and prints nothing but a summary when the ledger is sound.
 * Exits 1 and lists every failing invariant otherwise.
 *
 * [main-sha], when given, is where main's own history is known to end --
 * this script is the one place LedgerValidator's "git facts" seam is fed
 * from (see that class's validate() docblock and item/ledger-main-push-
 * record, issue #172): it walks `git log --first-parent
 * <previous completed run's commit>..<main-sha>` and hands the resulting
 * shas to the validator, which asserts every one of them appears in some
 * run's merges. This is only meaningful with the repository's full history
 * available (.github/workflows/ledger.yml checks out at fetch-depth 0); a
 * shallow clone, or no third argument at all, simply skips the check -- the
 * validator already treats an omitted git fact as "nothing to check
 * against", not as a failure, so a caller with no such fact (this script
 * run without it, or the Pest test that requires the same class directly)
 * is never penalised for not having it.
 *
 * <main-sha> is deliberately NOT `HEAD`: on a pull_request event,
 * actions/checkout leaves HEAD on a synthetic merge-preview commit whose
 * first parent is the PR's base and whose second parent (the PR's own
 * commits) `--first-parent` never walks -- so `git log --first-parent
 * X..HEAD` there answers a different question than it does on push. Main's
 * own history is the same question on both event types ("does the ledger
 * account for every merge already on main?"), so the workflow passes the
 * commit GitHub itself calls main for that event -- github.sha on push,
 * github.event.pull_request.base.sha on pull_request -- rather than
 * whatever ref happens to be checked out.
 */

$root = dirname(__DIR__, 2);

require $root.'/app/Support/LedgerValidator.php';

$ledgerDir = $argv[1] ?? $root.'/docs/ledger';
$specPath = $argv[2] ?? $root.'/docs/superpowers/specs/2026-09-15-doccum-design.md';
$mainSha = $argv[3] ?? null;
if ($mainSha === '') {
    $mainSha = null;
}

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

/**
 * The previous completed run's commit: the highest-numbered run file whose
 * outcome is "completed". Everything on main after this point, up to
 * <main-sha>, is expected to be recorded in SOME run's merges by the time
 * this check runs. Reads run files directly rather than through the
 * validator -- this script supplies git facts to LedgerValidator, it does
 * not borrow the validator's own parsing to do it, so a malformed run file
 * here simply yields no fact rather than duplicating LedgerValidator's own
 * "fatal:" handling.
 */
function previousCompletedRunCommit(string $ledgerDir): ?string
{
    $paths = glob($ledgerDir.'/runs/*.jsonld');
    if ($paths === false || $paths === []) {
        return null;
    }
    sort($paths, SORT_STRING);

    $best = null;
    foreach ($paths as $path) {
        $raw = file_get_contents($path);
        if ($raw === false) {
            continue;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ($decoded['outcome'] ?? null) !== 'completed') {
            continue;
        }
        $commit = $decoded['commit'] ?? null;
        $number = is_int($decoded['identifier'] ?? null) ? $decoded['identifier'] : null;
        if (is_string($commit) && $number !== null && ($best === null || $number > $best['number'])) {
            $best = ['number' => $number, 'commit' => $commit];
        }
    }

    return $best['commit'] ?? null;
}

/**
 * `git log --first-parent <since>..<upTo>`, as a list of shas oldest-first
 * (matching Run.merges' own chronological order). Returns null -- not an
 * empty list -- on ANY failure (unknown revision, shallow history, git not
 * on PATH), so the caller can tell "no commits in range" apart from
 * "could not compute the range at all" and skip the check in the latter
 * case rather than reporting every real commit as missing.
 *
 * @return ?list<string>
 */
function firstParentShasSince(string $root, string $since, string $upTo): ?array
{
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        ['git', 'log', '--first-parent', '--format=%H', '--reverse', "$since..$upTo"],
        $descriptor,
        $pipes,
        $root
    );
    if (! is_resource($process)) {
        return null;
    }

    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || $out === false) {
        return null;
    }

    $shas = array_values(array_filter(array_map('trim', explode("\n", $out)), fn (string $line): bool => $line !== ''));

    foreach ($shas as $sha) {
        if (! preg_match('/^[0-9a-f]{40}$/', $sha)) {
            return null;
        }
    }

    return $shas;
}

$mainPushShas = null;
if ($mainSha !== null) {
    $previousCommit = previousCompletedRunCommit($ledgerDir);
    if ($previousCommit === null) {
        fwrite(STDERR, "Note: no previously completed run has a commit to diff from; skipping the main-push-history check.\n");
    } else {
        $mainPushShas = firstParentShasSince($root, $previousCommit, $mainSha);
        if ($mainPushShas === null) {
            fwrite(STDERR, "Note: could not compute git history from $previousCommit to $mainSha (shallow clone?); skipping the main-push-history check.\n");
        }
    }
}

$errors = App\Support\LedgerValidator::validate($ledgerDir, $specPath, $mainPushShas);

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

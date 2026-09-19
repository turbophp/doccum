// Drives the shipped doccum container over real HTTP, the way an operator
// would: browser at /setup, upload through the files UI, search through the
// search UI. See CLAUDE.md and docs/superpowers/specs/2026-09-15-doccum-design.md
// §13 -- the container is the product, and search in particular shipped
// completely inert once while 370 unit/feature tests stayed green (see
// tests/Feature/Search/EndToEndSearchTest.php), which is exactly the class of
// bug only a real end-to-end run against the real container can catch.
//
// Why a browser instead of raw curl against /livewire/update: every
// meaningful step here -- the first-run installer, the file upload (Livewire
// temporary-upload protocol), and the debounced live search -- is a Livewire
// component whose submissions are JSON payloads keyed to a checksum signed
// server-side and normally assembled by Livewire's own client JS. There is no
// doccum: artisan command and no documented API endpoint for any of this (see
// routes/web.php and app/Console/Commands -- checked before writing this).
// Reconstructing that wire protocol by hand for three unrelated components
// would be more fragile than driving the real client the way a real operator
// does, so this uses a real headless browser (Playwright, installed ad hoc by
// the workflow -- see the "Install Playwright" step -- and never added to
// package.json).
//
// Run as: node .github/scripts/container-smoke.mjs <setup|seed-stale|verify>
//   setup      -- complete the installer, check the topbar shell, upload a
//                 file, wait for extraction, confirm it is findable by
//                 search. Run once, against a freshly booted, empty-volume
//                 container.
//   seed-stale -- item/upgrade-smoke (issue #210): writes a user
//                 row with email_verified_at NULL and a role edited away
//                 from RolesAndPermissionsSeeder's defaults directly into
//                 the database, and rolls back the backfill migration's own
//                 `migrations` row so the next boot genuinely re-runs it --
//                 making this volume look like it predates the candidate
//                 image rather than merely holding a static edited row. Run
//                 against the STILL-RUNNING setup container, before it is
//                 replaced. See seedStaleRows()'s own docblock.
//   verify     -- log in again and confirm the file and its search hit
//                 survived the container being replaced, that the
//                 seed-stale row and role edit survived it too, then log
//                 out through the account menu. Run in a fresh browser
//                 context (no cookies carried over), against a NEW
//                 container started from the same image on the same named
//                 volume -- not `docker restart`, which would keep the old
//                 container's writable layer and prove nothing (issue #91).
//
// All credentials and the marker text searched for come from the environment
// (set by the workflow step that invokes this), so all three phases agree on
// them without this script persisting any state of its own between
// invocations.

import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

function env(name, fallback) {
  const value = process.env[name];
  if (value !== undefined && value !== '') return value;
  if (fallback !== undefined) return fallback;
  throw new Error(`Missing required environment variable ${name}`);
}

const BASE_URL = env('BASE_URL', 'http://127.0.0.1:8080');
const CONTAINER_NAME = env('CONTAINER_NAME', 'doccum-smoke');

const INSTANCE_NAME = env('SMOKE_INSTANCE_NAME');
const ADMIN_NAME = env('SMOKE_ADMIN_NAME');
const ADMIN_USERNAME = env('SMOKE_ADMIN_USERNAME');
const ADMIN_EMAIL = env('SMOKE_ADMIN_EMAIL');
const ADMIN_PASSWORD = env('SMOKE_ADMIN_PASSWORD');
const FILE_NAME = env('SMOKE_FILE_NAME');
const FILE_MARKER = env('SMOKE_FILE_MARKER');

// A second, throwaway upload dedicated to checkTrashRemovesFileFromListingAndSearch()
// below -- deliberately NOT the same file FILE_NAME/FILE_MARKER identify.
// Those two survive into the 'verify' phase to prove the container being
// replaced did not lose data; trashing that same file here would falsify
// that check for a completely unrelated reason. No new env var: a fixed,
// distinctive literal is enough since nothing else needs to agree on it.
const TRASH_CHECK_FILE_NAME = 'DoccumSmokeTrashTarget.txt';

// item/files-versions-replace (issue #102): a dedicated document for
// checkReplaceAddsASecondVersion() below, deliberately NOT FILE_NAME. The
// 'verify' phase re-finds FILE_NAME by FILE_MARKER inside its body -- if
// this replaced that file's bytes with a v2 body carrying no marker,
// 'verify' would fail for a reason that looks nothing like this item.
const VERSIONS_CHECK_FILE_NAME = 'DoccumSmokeVersionTarget.txt';

// item/trash-view (issue #15): the pair of documents checkTrashViewRestoreAndPurge()
// below drives through the new /trash page's own Restore and Purge controls.
// Two names, not one, so the check can prove both directions independently --
// one comes back live, the other is gone for good -- rather than reusing
// TRASH_CHECK_FILE_NAME, which checkTrashRemovesFileFromListingAndSearch()
// above already trashes through a different door (the file detail panel) and
// never restores.
const TRASH_VIEW_RESTORE_FILE_NAME = 'DoccumSmokeTrashViewRestore.txt';
const TRASH_VIEW_PURGE_FILE_NAME = 'DoccumSmokeTrashViewPurge.txt';

const EXTRACTION_TIMEOUT_MS = Number(env('EXTRACTION_TIMEOUT_MS', '60000'));
const POLL_INTERVAL_MS = Number(env('POLL_INTERVAL_MS', '2000'));
const SEARCH_TIMEOUT_MS = Number(env('SEARCH_TIMEOUT_MS', '20000'));
const REPLACE_TIMEOUT_MS = Number(env('REPLACE_TIMEOUT_MS', '20000'));

// Only used by checkForwardedPasswordResetUrl() below (item/reverse-proxy-ready,
// issue #58), which only the 'setup' phase calls -- read with a fallback here
// so the 'verify' phase (whose step sets no such env var at all) never trips
// env()'s "missing required variable" check just by loading this module.
const SMOKE_FORWARDED_HOST = env('SMOKE_FORWARDED_HOST', '');

// item/upgrade-smoke (issue #210): seedStaleRows() (the
// 'seed-stale' phase) and checkStaleUserReachesTheApp()/
// checkStaleRolePermissionStaysRevoked() (called from 'verify') all need to
// agree on this one account, across two separate `node` invocations in the
// same job -- so, like ADMIN_* above, it comes from the environment rather
// than being generated twice. Fallbacks to '' rather than a required read:
// the 'setup' phase loads this same module but never touches these, and
// should not have to know they exist.
const STALE_USER_USERNAME = env('SMOKE_STALE_USER_USERNAME', '');
const STALE_USER_EMAIL = env('SMOKE_STALE_USER_EMAIL', '');
const STALE_USER_PASSWORD = env('SMOKE_STALE_USER_PASSWORD', '');

// The role and permission seedStaleRows() edits to look like an operator's
// change that predates the replacement, and the pair
// checkStaleRolePermissionStaysRevoked() reads back afterwards. Not
// threaded through the environment like the credentials above: unlike a
// per-run email or password, nothing about these two needs to differ
// between runs or agree with anything outside this file, so a fixed
// default is enough. 'member'/'files.restore' is deliberately NOT
// periods.manage -- checkAdminRolesPage() (runSetup()) and
// checkRolePermissionSurvivesContainerReplacement() (runVerify()) already
// own that permission for the opposite edit (grant, not revoke); reusing it
// here would make the two checks step on each other's state.
const STALE_ROLE_NAME = env('SMOKE_STALE_ROLE_NAME', 'member');
const STALE_REMOVED_PERMISSION = env('SMOKE_STALE_REMOVED_PERMISSION', 'files.restore');

// database/migrations/2026_09_18_150000_backfill_email_verified_at_for_existing_users.php's
// own name, exactly as Laravel records it in the `migrations` table (the
// filename minus `.php`). Identifies a FILE in this repository, not
// something that varies by run, so it is a literal here rather than an env
// var -- see seedStaleRows()'s docblock for why this needs to be deleted at
// all.
const STALE_BACKFILL_MIGRATION = '2026_09_18_150000_backfill_email_verified_at_for_existing_users';

const phase = process.argv[2];
if (phase !== 'setup' && phase !== 'seed-stale' && phase !== 'verify') {
  console.error('Usage: node container-smoke.mjs <setup|seed-stale|verify>');
  process.exit(2);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Runs a PHP snippet inside the running container via `artisan tinker`,
 * the same way a self-hoster would inspect a stuck instance -- no new
 * application code, just the artisan surface the image already ships with
 * (laravel/tinker is a production dependency; see composer.json).
 *
 * Arguments are passed to `docker` as an argv array (spawnSync/execFileSync,
 * no shell), so the PHP source needs no shell escaping.
 */
function tinker(php) {
  try {
    return execFileSync(
      'docker',
      ['exec', CONTAINER_NAME, 'php', 'artisan', 'tinker', '--execute', php],
      { encoding: 'utf8', timeout: 20000 },
    );
  } catch (e) {
    // execFileSync's `message` is only "Command failed: <the command>", so a
    // caller that reports e.message reports the command back and says nothing
    // about why. An image job did exactly that and cost several runs. The
    // cause lives on the error OBJECT: `status` is the exit code,
    // `signal`/`killed` separate the 20s timeout from a non-zero exit, and
    // stdout/stderr carry what the process actually said. See issue #106.
    const detail = [
      `exit status: ${e.status ?? '(none)'}`,
      `signal: ${e.signal ?? '(none)'}`,
      `killed by timeout: ${e.killed === true}`,
      `stdout: ${String(e.stdout ?? '').trim() || '(empty)'}`,
      `stderr: ${String(e.stderr ?? '').trim() || '(empty)'}`,
    ].join('\n    ');

    const err = new Error(`artisan tinker failed inside ${CONTAINER_NAME}\n    ${detail}`);
    err.cause = e;
    throw err;
  }
}

/**
 * config('doccum.version') as the RUNNING CONTAINER reports it. Read from
 * inside the image rather than from the checkout on purpose: a version pill
 * compared against the same constant the view rendered from can only ever
 * agree with itself.
 */
function versionFromContainer() {
  const output = tinker("echo 'VERSION:' . config('doccum.version');");
  const match = /VERSION:(\S+)/.exec(output);
  if (!match) {
    throw new Error(`could not read config('doccum.version') from the container: ${output.trim()}`);
  }
  return match[1];
}

/**
 * org.opencontainers.image.version off the IMAGE itself, via `docker inspect`
 * -- a genuinely different source from the PHP process versionFromContainer()
 * reads. Set by the Dockerfile's `ARG DOCCUM_VERSION` / `LABEL
 * org.opencontainers.image.version` (item/version-from-tag), and inherited by
 * every container started from the image, so inspecting the running
 * container's own config is equivalent to inspecting the image and needs no
 * separate image name/tag to be threaded through this script.
 */
function versionLabelFromContainer() {
  const output = execFileSync(
    'docker',
    ['inspect', '-f', '{{ index .Config.Labels "org.opencontainers.image.version" }}', CONTAINER_NAME],
    { encoding: 'utf8' },
  ).trim();
  if (!output) {
    throw new Error(
      `docker inspect reported no org.opencontainers.image.version label on ${CONTAINER_NAME} -- ` +
        'the image was not built with the Dockerfile\'s DOCCUM_VERSION ARG/LABEL',
    );
  }
  return output;
}

function dumpContainerState(reason) {
  console.error(`\n::error::${reason}`);
  try {
    console.error('\n--- docker logs ---');
    console.error(execFileSync('docker', ['logs', CONTAINER_NAME], { encoding: 'utf8' }));
  } catch (e) {
    console.error(`(could not fetch docker logs: ${e.message})`);
  }
  try {
    console.error('\n--- supervisorctl status (queue workers) ---');
    console.error(
      // -c matters: supervisord runs with this config (see the image's CMD),
      // and without it supervisorctl looks for a socket at a default path that
      // does not exist here, so the diagnostic reported nothing on the one
      // run where it was needed.
      execFileSync('docker', ['exec', CONTAINER_NAME, 'supervisorctl', '-c', '/etc/supervisor/conf.d/doccum.conf', 'status'], { encoding: 'utf8' }),
    );
  } catch (e) {
    console.error(`(could not fetch supervisorctl status: ${e.message})`);
  }
}

// Fortify's default path for its `verification.notice` route -- see
// laravel/fortify's routes/routes.php,
// `RoutePath::for('verification.notice', '/email/verify')` -- and NOT
// overridden here: config/fortify.php was read before writing this and
// carries no path override. If that ever changes, the constant is the one
// place to fix it, not either of the two call sites below.
const VERIFICATION_NOTICE_PATH = '/email/verify';

/**
 * item/smoke-installer-names-the-bounce (issue #197). Two places in this
 * file report a destination the browser was asked to reach, and both used
 * to be misreadable in the same way: mutation/0019 sent checkAdminUsersPage()
 * an HTTP 200 from `/admin/users` that read exactly like an authorisation
 * bypass and was not one. Playwright's goto() and waitForURL() both follow
 * redirects, so a status code, or a bare "did the URL change", describes
 * whatever the browser was FINALLY sent to -- not the page that was asked
 * for. The member in that run held no `users.manage`, was unverified, and
 * had been redirected to Fortify's own verification notice; the 200 was
 * that notice rendering, not `/admin/users` answering for real.
 *
 * So both sites now go through this one helper instead of each inlining its
 * own "where did we end up" text: one wording to keep honest, not two that
 * read alike and can drift. It reports the pathname actually reached, and
 * names the verification notice explicitly rather than leaving a caller to
 * rediscover, from a raw path or a raw status, that a bounce happened at
 * all -- which is the exact rediscovery mutation/0019 had to do by reading
 * the check's source rather than its output.
 */
function describeLanding(page) {
  const pathname = new URL(page.url()).pathname;
  return pathname === VERIFICATION_NOTICE_PATH
    ? `bounced to the email verification notice (${pathname}) instead of the page this was asked for`
    : `landed on ${pathname}`;
}

/**
 * Whether a files row exists for this name, asked once. No waiting, no
 * retrying -- callers decide what to do about a `false`.
 *
 * This is the question the upload step must actually ask. Its own assertion,
 * page.getByText(name), matches the file input's displayed filename and
 * Livewire's optimistic preview, neither of which needs anything to have been
 * stored: it reported "upload accepted, file listed in the directory" through
 * a run in which File::where('name', ...) returned null for the next sixty
 * seconds, and the run then failed elsewhere, at extraction, for a reason
 * that looked nothing like the cause.
 *
 * decision/0012: prefer an assertion that requires the feature to DO
 * something over one that observes a resting state. See issue #106.
 */
function fileRowExists(name) {
  const php = [
    `$f = \\App\\Models\\File::where('name', '${name}')->first();`,
    "echo 'ROW:' . ($f ? 'yes' : 'no');",
  ].join(' ');

  return /ROW:yes/.test(tinker(php));
}

/**
 * Sets a file on an upload input and does not return until that file's bytes
 * have actually landed in the component -- the upload-file POST answered AND
 * the _finishUpload commit behind it settled.
 *
 * clickOnceUploadSettles() below used to be the whole of this: watch the
 * submit button go disabled, wait for it to come back, click. That reads the
 * upload's progress off an ATTRIBUTE, and the attribute is not a clean
 * single edge. #148's run caught the gap on the first check that uploads two
 * files into one page -- the second upload's probe reads, in order:
 *
 *   POST .../update        req 53.4297  resp 53.4644   (_startUpload)
 *   POST .../upload-file   req 53.4671                 (the bytes)
 *   POST .../update        req 53.5004  resp 53.5601   <-- the racing click
 *                          resp 53.5684                (upload-file answers)
 *   POST .../update        req 53.5741  resp 53.6044   (_finishUpload, 30ms)
 *
 * The click's commit went out 67ms BEFORE the bytes were acknowledged, so
 * store() ran against an empty property exactly as issue #106 describes, and
 * the run died at "no files row for DoccumSmokeTrashViewPurge.txt". The
 * disabled-edge watcher had already seen an edge and an enable by then --
 * whether that was the tail of the PREVIOUS upload or a gap between
 * _startUpload's response and the upload POST does not matter, because
 * either way a poll that latches onto the first enable it sees cannot tell
 * "this upload has finished" from "some upload has finished".
 *
 * So wait on the upload's own request lifecycle instead, which is the only
 * thing here that is true at the destination and nowhere else. The listener
 * is armed BEFORE setInputFiles(): a file input posts on change, and a
 * listener registered afterwards can miss the response outright.
 *
 * Nothing is asserted here -- an upload too fast to observe, or a Livewire
 * that renames the endpoint, must not turn into a failure in a helper whose
 * job is to get out of the way. The guard itself is proved, against a
 * deliberately stalled endpoint, by
 * checkUploadButtonIsDisabledWhileTheFileIsStillUploading().
 */
async function setFileAndWaitForUpload(page, input, filePath) {
  const posted = page
    .waitForResponse((response) => response.url().includes('/upload-file'), { timeout: 20000 })
    .catch(() => null);

  await input.setInputFiles(filePath);
  await posted;

  // The bytes are acknowledged; _finishUpload is the commit that puts them on
  // the component's property, and it is still in flight at this point.
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
}

/**
 * Clicks a locator and waits for the Livewire round trip THAT CLICK starts,
 * rather than for a bare networkidle registered after the click.
 *
 * Issue #182: networkidle resolves once the page has had no network activity
 * for 500ms, but Livewire does not issue its XHR synchronously inside the
 * click handler -- the page can still be idle at the instant
 * waitForLoadState('networkidle') is called, so it resolves immediately,
 * before the request it was meant to settle has even started. The very next
 * statement at both call sites this replaces shells into the container and
 * runs tinker, which takes roughly a second to boot Laravel -- just about
 * enough to lose that race, and on run 35328394591's image job it did:
 * RESTORE_LIVE:yes PURGED_GONE:no, with no 4xx or 5xx anywhere in the
 * container access log, because the purge's Livewire POST was still in
 * flight when the database was read.
 *
 * So the response listener is armed BEFORE the click, the way
 * setFileAndWaitForUpload() above already does for the upload endpoint. The
 * matcher tests /livewire/i against the whole URL rather than a guessed
 * prefix, because the Livewire endpoint carries a per-install hash (observed
 * as /livewire-2dbf666b/update) -- run/0014's note near
 * instrumentUploadPath() above is exactly the lesson here: "an instrument may
 * not assume the shape of what it is measuring." A guessed prefix would
 * silently stop matching on a different install and this helper would degrade
 * to the bug it was written to fix without ever failing loudly.
 *
 * The .catch(() => null) is kept for the same reason setFileAndWaitForUpload()
 * keeps its own: a future Livewire release that renames the endpoint must
 * degrade this helper to today's networkidle-only behaviour, not turn the
 * helper itself into a failure.
 *
 * This does not violate decision/0033 ("never wait on the thing under test"):
 * it waits for the REQUEST the click caused, never for the row disappearing
 * or the file being gone -- the database read that follows remains the
 * assertion, unchanged and still first.
 */
async function clickAndWaitForLivewire(page, locator, options = {}) {
  const settled = page
    .waitForResponse((r) => r.request().method() === 'POST' && /livewire/i.test(r.url()), { timeout: 10000 })
    .catch(() => null);

  await locator.click(options);
  await settled;

  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
}

/**
 * Clicks a submit button that Livewire disables for the duration of a file
 * upload, WITHOUT racing that disabled window.
 *
 * Issue #106's fix gives both upload forms
 * wire:loading.attr="disabled" wire:target="<upload property>", so the button
 * goes enabled -> disabled -> enabled around every setInputFiles(). Clicking
 * straight afterwards is a check-then-act race against that transition:
 * Playwright's actionability check can pass a microsecond before the
 * attribute lands, the click is then dispatched at a disabled <button>, and
 * the browser drops it silently. Playwright reports success -- it did
 * dispatch -- so nothing throws, and the only trace is the commit that never
 * went.
 *
 * That is exactly how main went red at 6663282, on a merge whose whole diff
 * was one ledger JSON file. The probe caught it precisely because the
 * upload-lifecycle requests were all there and the store() commit was not:
 *
 *   POST .../update        200   (_startUpload)
 *   POST .../upload-file   200   (the bytes)
 *   POST .../update        200   (_finishUpload)
 *   <nothing>                    (store never dispatched)
 *
 * A person never hits this: they see a disabled button and click when it
 * looks clickable. It is a harness artifact created by the product fix, and
 * the honest repair is to wait for the upload to SETTLE rather than to
 * restore the retry that #129 deliberately removed -- a retry here would
 * once again convert a timing defect into a green run, which is how #106
 * survived three rounds in the first place.
 *
 * So: wait for the disabled edge (bounded, and tolerated if the upload is too
 * fast to observe it -- absence of the edge is not evidence of anything),
 * then wait for it to clear, and only then click. After the upload has
 * landed nothing disables the button again, so the click cannot be dropped.
 *
 * This is now the LAST-MILE guard, not the whole of it. Every caller reaches
 * here through setFileAndWaitForUpload() above, which has already waited the
 * upload-file POST out, so in a healthy run this loop sees no disabled edge
 * at all and falls straight through to the click -- which is the point. It
 * stays because it costs one poll and it is the thing that still catches a
 * button left disabled by a stuck loading state, a case the request wait
 * cannot see.
 */
async function clickOnceUploadSettles(page, button, phase, label) {
  let sawDisabled = false;

  // 750ms, not the 5000ms this used to wait. Since setFileAndWaitForUpload()
  // started waiting the upload-file POST out before we get here, the disabled
  // edge is already GONE in a healthy run -- so "not seen" became the normal
  // case rather than the rare one, and the old budget was spent in full
  // before every click in the run. The wait that matters moved upstream; what
  // is left here is the narrow case of a button wedged disabled by a stuck
  // loading state, which shows up immediately or not at all.
  for (let waited = 0; waited < 750; waited += 50) {
    if (await button.isDisabled()) {
      sawDisabled = true;
      break;
    }
    await sleep(50);
  }

  if (sawDisabled) {
    let settled = false;
    for (let waited = 0; waited < 20000; waited += 50) {
      if (await button.isEnabled()) {
        settled = true;
        break;
      }
      await sleep(50);
    }

    if (! settled) {
      dumpContainerState(
        `[${phase}] ${label} stayed disabled for 20s after the file was chosen` +
        ' -- the upload never finished, or the loading state is stuck',
      );
      throw Object.assign(new Error(`${label} never re-enabled`), { dumped: true });
    }
  }

  await button.click({ timeout: 10000 });
}

/**
 * Uploads a file through the browser and does not return until a files row
 * exists for it. ONE upload, ONE click: no retry, no second chance. The wait
 * for the row to appear is a wait and not a retry -- see the poll at the
 * bottom of this function for why that distinction is the whole point.
 *
 * This helper used to retry three times, because setting a file on the input
 * and clicking Upload silently stored nothing often enough to break unrelated
 * checks (issue #106). The retry described that as "no request, no error",
 * and was wrong on the first half -- a claim never actually observed, only
 * inferred, and it sent three rounds of work looking in the wrong place.
 *
 * PR #129 instrumented every non-asset request and caught the discard three
 * times in one run. There IS a request, it answers 200, and the split is
 * latency:
 *
 *   discarded   POST .../upload-file           request at t+0
 *               POST .../update  (store)       request at t+14ms  <-- races
 *               no files row                   at t+565ms
 *               200 .../upload-file            at t+570ms
 *
 *   stored      POST .../upload-file           request at t+0
 *               200 .../upload-file            at t+18ms
 *               POST .../update  (store)       request at t+24ms  <-- after
 *
 * A file input posts its bytes the moment it changes, and the component's
 * property is not populated until that POST answers. Clicking Upload inside
 * that window dispatches store() against an empty property; it fails
 * `required|file`, and the _finishUpload commit that lands a moment later
 * re-renders over the error. Nothing stored, nothing said -- and a retry
 * "works" only because by then the upload has landed, which is why three
 * attempts always hid it.
 *
 * The fix is in resources/views/livewire/files/browser.blade.php: the submit
 * button is disabled for the whole upload, so the racing click cannot be
 * made. Retrying here would hide a regression of exactly that, so this does
 * not retry.
 *
 * What this helper does NOT prove, stated plainly because it used to claim
 * otherwise: it is no longer sensitive to the product guard. It used to say
 * that removing wire:loading.attr="disabled" would leave some upload in this
 * run storing nothing -- true while the click was timed off that very
 * attribute, and false since setFileAndWaitForUpload() started waiting the
 * upload-file POST out instead. With the bytes already acknowledged before
 * the click, an undisabled button stores just fine here. That is the price
 * of a helper that does not race, and it moves the whole weight of the guard
 * onto one assertion elsewhere:
 * checkUploadButtonIsDisabledWhileTheFileIsStillUploading() stalls the upload
 * endpoint on purpose and asserts the button disabled inside that window --
 * which is why that check still sets its file by hand rather than through
 * the helper above. One check proves the guard; this one proves an upload
 * stores. Neither pretends to do the other's job.
 *
 * That one assertion is mutation-proven, which it had not been: PR #149
 * removed wire:loading.attr="disabled" wire:target="upload" from the Upload
 * button and nothing else, and the check failed with its own message --
 * after printing its "starts enabled (control, not evidence)" line, so it
 * reached its subject rather than dying on the way. decision/0030 had called
 * it load-bearing on reasoning rather than on a run, which was tolerable
 * while this helper was also sensitive to the guard and stopped being so the
 * moment it wasn't.
 */
/**
 * New folder and Upload live in dialogs now, so their controls are hidden
 * until the dialog is open. fill() and click() both check actionability, so
 * the opening click is not optional -- this is exactly the "a control inside
 * a closed popover is one it cannot reach" case CLAUDE.md warns about, met
 * head-on rather than by leaving the controls lying on the toolbar.
 *
 * Both are idempotent: the dialog is x-show, so clicking the opener while it
 * is already open is harmless.
 */
async function openNewFolderDialog(page) {
  await page.locator('[data-test="new-folder-button"]').click();
  await page.locator('[data-test="new-folder-modal"]').waitFor({ state: 'visible', timeout: 10000 });
}

async function openUploadDialog(page) {
  await page.locator('[data-test="open-upload-button"]').click();
  await page.locator('[data-test="upload-modal"]').waitFor({ state: 'visible', timeout: 10000 });
}

async function uploadAndProveStored(page, name, contents, phase) {
  const tmpFile = path.join(os.tmpdir(), name);
  fs.writeFileSync(tmpFile, contents);

  await openUploadDialog(page);

  // Through setFileAndWaitForUpload(), never a bare setInputFiles(): this
  // helper is called twice in a row by checkTrashViewRestoreAndPurge(), and
  // the second call is where watching the button alone was caught clicking
  // 67ms before the bytes were acknowledged.
  await setFileAndWaitForUpload(
    page,
    page.locator('[data-test="upload-form"] input[type="file"]'),
    tmpFile,
  );

  // Through clickOnceUploadSettles(), never a bare click: the button is
  // deliberately disabled for the duration of the upload, and clicking into
  // that transition is a race the browser resolves by dropping the click.
  await clickOnceUploadSettles(
    page,
    page.getByRole('button', { name: 'Upload', exact: true }),
    phase,
    'the Upload button',
  );

  // Poll the DATABASE, not the DOM. The previous gate here waited for
  // getByText(name) and then read the row ONCE, which is a race it had been
  // winning by luck: the chosen filename is on the page as soon as the form
  // re-renders, well before store() has finished writing. main went red at
  // 9e2f7af -- a LEDGER-ONLY merge -- with the probe showing the submit
  // commit dispatched at 20.7111 and the check giving up at 21.2657, 555ms
  // later. A getByText that had actually timed out would have failed at
  // 30.7; failing at 21.26 proves it matched at once and the single read
  // simply arrived before the write.
  //
  // This is a wait, not a retry, and the distinction is the one #129 turned
  // on. Nothing is uploaded again and nothing is clicked again -- the commit
  // has definitively been dispatched, and this waits for its write to land.
  // A retry would re-attempt the upload and convert a timing defect into a
  // green run, which is how issue #106 survived three rounds; a poll cannot,
  // because a click that was dropped never writes a row no matter how long
  // this waits.
  const rowDeadline = Date.now() + 15000;
  let stored = fileRowExists(name);

  while (! stored && Date.now() < rowDeadline) {
    await sleep(POLL_INTERVAL_MS);
    stored = fileRowExists(name);
  }

  if (! stored) {
    dumpContainerState(`${name} was uploaded and left no files row -- issue #106 has regressed`);
    throw Object.assign(new Error(`no files row for ${name}`), { dumped: true });
  }

  console.log(`[${phase}] ${name} stored, confirmed by a files row`);
}

/**
 * Polls file_texts.status (via the File -> currentVersion -> text chain,
 * matching app/Models/File.php and app/Models/FileVersion.php) for the file
 * named FILE_NAME, bounded by EXTRACTION_TIMEOUT_MS. Never hangs: a status
 * that never reaches "done" -- including one that reaches "failed" or
 * "unsupported" -- fails loudly with container state rather than polling
 * forever.
 */
async function waitForExtraction() {
  // Branches rather than calling `exit`. PsySH leaves the process with status
  // 1 when --execute code exits, so execFileSync threw and the NO_FILE and
  // NO_VERSION readings -- the two most diagnostic answers this query has --
  // were turned into "the tinker call failed" and never reached the poller.
  // Both have been unreachable for as long as they have existed: one run
  // carried STATUS:NO_FILE in stdout behind a thrown exception. Issue #106.
  const php = [
    `$f = \\App\\Models\\File::where('name', '${FILE_NAME}')->first();`,
    "if (!$f) { echo 'STATUS:NO_FILE'; } else {",
    '$f->refresh();',
    '$v = $f->currentVersion;',
    "if (!$v) { echo 'STATUS:NO_VERSION'; } else {",
    '$t = $v->text;',
    "echo 'STATUS:' . ($t ? $t->status->value : 'NO_TEXT');",
    // ExtractText::failed() records why in file_texts.error. Without this the
    // smoke reports only that extraction failed, which is how a terminal
    // failure in CI was diagnosed by guesswork rather than by reading the
    // exception -- see issue #57.
    "if ($t && $t->error) { echo ' ERROR:' . $t->error; }",
    '} }',
  ].join(' ');

  const deadline = Date.now() + EXTRACTION_TIMEOUT_MS;
  let last = 'unknown';

  while (Date.now() < deadline) {
    let output;
    try {
      output = tinker(php);
    } catch (e) {
      output = `STATUS:TINKER_ERROR(${e.message})`;
    }

    const match = /STATUS:(\S+)/.exec(output);
    last = match ? match[1] : output.trim();

    if (last === 'done') return;

    if (last === 'failed' || last === 'unsupported') {
      const recorded = /ERROR:([\s\S]*)$/.exec(output);
      const why = recorded ? recorded[1].trim() : '(file_texts.error was empty)';

      dumpContainerState(
        `extraction reached a terminal non-success status (${last}) for ${FILE_NAME}\n` +
          `file_texts.error: ${why}`,
      );
      const err = new Error(`extraction status "${last}" for ${FILE_NAME}: ${why}`);
      err.dumped = true;
      throw err;
    }

    await sleep(POLL_INTERVAL_MS);
  }

  dumpContainerState(
    `extraction for ${FILE_NAME} did not reach "done" within ${EXTRACTION_TIMEOUT_MS}ms (last status: ${last})`,
  );
  const err = new Error(`timed out waiting for extraction (last status: ${last})`);
  err.dumped = true;
  throw err;
}

// Searching once and waiting is not enough, and the reason is worth knowing.
// ExtractText writes file_texts.status = done and only THEN dispatches
// ReindexSearchDocument as a separate queued job (app/Jobs/ExtractText.php),
// so "extraction finished" genuinely precedes "findable". The default queue
// worker runs without --sleep (docker/supervisor/doccum.conf), which means
// Laravel's 3-second idle poll: an upload can sit unindexed for a few seconds
// after its status flips.
//
// The search page has no wire:poll, so a page that rendered "no results"
// stays that way forever -- the first run failed against a 20s timeout that
// could never have expired usefully, because nothing was going to re-render.
// Refilling the same value does not retrigger Livewire's debounced
// wire:model.live either, hence clearing first to force a fresh request.
//
// This still asserts the document becomes findable. It just stops assuming
// that happens synchronously.
async function searchUntilFound(page, phase) {
  const deadline = Date.now() + SEARCH_TIMEOUT_MS;
  const field = page.getByLabel('Search', { exact: true });

  while (Date.now() < deadline) {
    await field.fill('');
    await field.fill(FILE_MARKER);

    const found = await page
      .getByText(FILE_NAME, { exact: true })
      .waitFor({ timeout: 2000 })
      .then(() => true)
      .catch(() => false);

    if (found) {
      return;
    }
  }

  dumpContainerState(`[${phase}] search never found ${FILE_NAME} within ${SEARCH_TIMEOUT_MS}ms`);
  throw Object.assign(new Error(`search timed out for ${FILE_NAME}`), { dumped: true });
}

/**
 * item/search-filters (issue #17): proves the period filter narrows the
 * search RESULTS, not merely that a Year field is present on the page. Called
 * right after searchUntilFound() above, so the browser is already on /search
 * with FILE_MARKER typed and FILE_NAME showing.
 *
 * period_year is fixed at upload time to the current UTC year (File::boot(),
 * app/Models/File.php) -- the app's timezone is UTC (config/app.php) -- so a
 * year five years out can never be this file's period on any clock. Typing it
 * into the Year field must make FILE_NAME disappear from the results; a
 * filter that renders a control but never reaches Search::for()'s query
 * would leave it exactly where it was. Clearing the field again must bring it
 * straight back, which is what tells a stuck "no results" render (a
 * component that broke rather than filtered) apart from a working filter.
 *
 * A Blade assertion cannot see this at all: it renders Results with whatever
 * $filters render() built, but never proves the wire:model.live binding on
 * the Year input actually reaches that property in a real browser -- exactly
 * the class of gap CLAUDE.md's container-smoke note describes.
 */
async function checkSearchFilterExcludesByPeriod(page, phase) {
  const wrongYear = String(new Date().getUTCFullYear() + 5);
  const yearField = page.getByLabel('Year', { exact: true });

  await yearField.fill(wrongYear);

  // FILE_NAME is already ON the page from searchUntilFound() above, so a
  // plain waitFor({state: 'visible'}) here would resolve true instantly --
  // it was true before the fill() ever ran, and would say nothing about
  // whether the debounced filter request changed anything. Waiting for
  // 'detached' instead only succeeds if the row actually leaves the DOM
  // AFTER this point, the same pattern checkTrashRemovesFileFromListingAndSearch()
  // uses above for exactly this reason.
  const excluded = await page
    .getByText(FILE_NAME, { exact: true })
    .waitFor({ state: 'detached', timeout: 5000 })
    .then(() => true)
    .catch(() => false);

  if (! excluded) {
    dumpContainerState(
      `[${phase}] the period filter did not exclude ${FILE_NAME} when Year was set to ${wrongYear}, `
      + 'a year it cannot carry',
    );
    throw Object.assign(new Error('search period filter did not narrow results'), { dumped: true });
  }

  console.log(`[${phase}] the period filter excludes ${FILE_NAME} under a year (${wrongYear}) it cannot carry -- OK`);

  await yearField.fill('');

  const foundAgain = await page
    .getByText(FILE_NAME, { exact: true })
    .waitFor({ timeout: SEARCH_TIMEOUT_MS })
    .then(() => true)
    .catch(() => false);

  if (! foundAgain) {
    dumpContainerState(`[${phase}] clearing the period filter never brought ${FILE_NAME} back`);
    throw Object.assign(new Error('search period filter did not clear'), { dumped: true });
  }

  console.log(`[${phase}] clearing the period filter restores ${FILE_NAME} -- OK`);

  // Settle before returning. fill('') above fires a DEBOUNCED Livewire
  // commit, and the row reappearing only proves one round trip landed, not
  // that the component is idle -- so without this the check hands the next
  // one a page with a request still in flight. decision/0033 records what
  // that costs: a check that leaves the browser mid-navigation blames its
  // successor, and the blame lands somewhere unrelated and expensive to
  // trace. This run failed six checks later with a wire:loading that never
  // cleared, which is what a wedged Livewire component looks like from the
  // outside.
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
}

/**
 * item/palette-smoke (issue #276): drives the command palette in a real browser.
 *
 * WHY THIS EXISTS AT ALL. The palette shipped with no container-smoke
 * assertion of any kind -- `grep palette .github/scripts/container-smoke.mjs`
 * returned nothing before this function -- while tests/Feature/Search/
 * PaletteTest.php covers the component thoroughly. That split is the exact
 * one CLAUDE.md warns about: every clause PaletteTest can reach is server
 * side (openPalette() sets a flag, hits() honours the viewer's reach,
 * destinationFor() resolves a route), and every clause that makes the palette
 * a palette rather than a Livewire property is browser side and was proved by
 * nothing:
 *
 *   - the window-level Ctrl-K / Cmd-K binding,
 *   - the topbar's open-search-palette CustomEvent and the palette's listener
 *     for it,
 *   - wire:model.live.debounce.200ms on an input that is created by the same
 *     round trip that opens the dialog,
 *   - Alpine's selected/openSelected keyboard navigation, which exists only in
 *     x-data and so is invisible to the test renderer entirely. move() is NOT
 *     driven: no arrow key is pressed, and with a single hit move(1, 1) lands
 *     back on 0, so an arrow assertion here would be satisfied by a no-op,
 *   - the escape-then-swap that turns the index's two private-use codepoints
 *     into <mark>, which nothing drives in a browser: the results page carries
 *     no data-test hooks at all.
 *
 * A Blade assertion renders palette.blade.php and sees the markup for all of
 * these. It cannot see that none of it booted.
 *
 * WHAT IS MUTATION-PROVEN, and what is not. mutation/0032 removed exactly one
 * attribute -- x-on:keydown.enter.prevent on the palette input -- and the
 * check failed at the LAST of its assertions with the three before it printing
 * OK. So one clause is proven: that a keydown.enter on the input reaches the
 * selected anchor and navigation follows. The other three passed in BOTH
 * directions, which is what makes them a control for that isolation and
 * exactly why they are not evidence about themselves.
 *
 * The obvious mutant was rejected, and the reason is the trap in this surface.
 * Ctrl-K is handled TWICE and independently: the topbar's focusSearch()
 * (layouts/app/topbar.blade.php) dispatches open-search-palette, and the
 * palette's own x-on:keydown.window.prevent.ctrl.k calls $wire.openPalette()
 * directly. Deleting either one alone leaves the keystroke working through the
 * other, so neither is a witness to itself -- CLAUDE.md's "counting readers is
 * not counting sources", arriving here as two writers of one behaviour. A
 * Ctrl-K mutant would have gone green and proved nothing.
 *
 * An earlier revision of this comment said the mutation "had to be" removing
 * the palette root's whole x-on set. It was not; that text described a run
 * nobody made, and it shipped that way in #277 before a consultation caught
 * it. Left recorded here rather than quietly overwritten, because a comment
 * vouching for evidence is the failure decision/0080 is about, and this file
 * is where a reader would have believed it.
 *
 * Called with the page already logged in and FILE_NAME already findable by
 * FILE_MARKER -- searchUntilFound() above established both -- and it starts by
 * navigating to /files ON PURPOSE. The palette's reason to exist is answering
 * a search over the top of whatever you were reading; driving it from /search
 * would prove it opens on the one page whose own field already searches.
 */
async function checkCommandPaletteOpensSearchesAndOpensAHit(page, phase) {
  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="search-palette"]').waitFor({ state: 'attached', timeout: 10000 });

  // Wait for Alpine, and wait for it on something only Alpine can produce.
  //
  // The palette root above is SERVER-rendered, so its presence says nothing
  // about whether Livewire has started Alpine and registered the window
  // listeners -- a keystroke that lands before that is simply lost, and this
  // check would then blame the binding for a race. The topbar's shortcut hint
  // (layouts/app/topbar.blade.php) ships as the literal '⌘K' and its x-data
  // init() rewrites it to 'Ctrl K' on anything that is not a Mac, which every
  // runner here is. Waiting for that text is therefore a wait on evaluated
  // Alpine rather than on delivered HTML.
  //
  // It is a precondition, not an assertion: if Alpine never booted, this times
  // out with its own message instead of leaving the Ctrl-K failure below to be
  // read as a broken binding.
  await page
    .locator('[data-test="search-shortcut-hint"]')
    .filter({ hasText: 'Ctrl K' })
    .waitFor({ state: 'attached', timeout: 15000 });

  const input = page.locator('[data-test="palette-input"]');

  // Resting state, and worth no more than that. palette.blade.php renders its
  // dialog inside @if ($open), so this element is absent from the DOM with no
  // JavaScript in the image at all -- exactly the half CLAUDE.md's topbar note
  // says was argued to be load-bearing and proved by mutation not to be. It is
  // here so that the wait below cannot resolve against a palette that was
  // already open, and for nothing else.
  if (await input.count() !== 0) {
    throw new Error('the palette was already open before Ctrl-K was pressed');
  }

  await page.keyboard.press('Control+k');

  try {
    await input.waitFor({ state: 'visible', timeout: 10000 });
  } catch {
    dumpContainerState(
      `[${phase}] Ctrl-K did not open the command palette -- [data-test="palette-input"] never appeared, so either`
      + ' the keydown binding, the open-search-palette listener or the Livewire round trip that renders the dialog'
      + ' is not running in the image',
    );
    throw Object.assign(new Error('Ctrl-K did not open the command palette'), { dumped: true });
  }

  console.log(`[${phase}] Ctrl-K opened the command palette -- the window binding and its Livewire round trip both ran`);

  // Same shape as searchUntilFound(): refill rather than fill once. The
  // component is freshly mounted here, so its query starts empty and one fill
  // is enough to trigger the debounced commit -- but the indexing race that
  // note describes is about the queue, not about this component, and a check
  // that can only ever see one commit turns a slow worker into a failure that
  // blames the palette. Clearing first is what makes a second commit fire at
  // all: refilling an unchanged value does not.
  const hit = page.locator('[data-test="palette-hit"]').filter({ hasText: FILE_NAME });
  const deadline = Date.now() + SEARCH_TIMEOUT_MS;
  let matched = false;

  while (Date.now() < deadline) {
    await input.fill('');
    await input.fill(FILE_MARKER);

    matched = await hit.first()
      .waitFor({ state: 'visible', timeout: 2000 })
      .then(() => true)
      .catch(() => false);

    if (matched) {
      break;
    }
  }

  if (! matched) {
    dumpContainerState(
      `[${phase}] typing ${FILE_MARKER} into the palette never produced a hit for ${FILE_NAME} within`
      + ` ${SEARCH_TIMEOUT_MS}ms, although the search page found the same document by the same word`,
    );
    throw Object.assign(new Error('the command palette returned no hit for an indexed document'), { dumped: true });
  }

  console.log(`[${phase}] the palette's debounced live query found ${FILE_NAME} by a word inside it`);

  // The snippet, and specifically the <mark> in it. SearchIndex wraps each
  // matched term in two private-use codepoints rather than HTML, and the view
  // escapes the whole snippet BEFORE swapping those two for <mark> -- the
  // order that makes document text safe to render unescaped. Nothing drove
  // that swap in a browser before this line, on this surface or the results
  // page. Asserting the marked text rather than merely that a <mark> exists:
  // a swap that produced an empty <mark> would satisfy the element and tell
  // the reader nothing about which word matched, which is the whole point of
  // showing a passage instead of the document's opening.
  //
  // 'attached', not 'visible', and textContent() rather than innerText(): the
  // snippet span is `truncate` (white-space: nowrap; overflow: hidden), so a
  // <mark> far enough along the passage is clipped by CSS rather than absent
  // from the DOM. Requiring it to be visible would make this assertion depend
  // on the viewport width, which is not what it is about. The hit itself is
  // already required to be visible by the wait above, so this is a <mark>
  // inside something the viewer can see.
  const marked = hit.first().locator('mark');

  try {
    await marked.first().waitFor({ state: 'attached', timeout: 10000 });
  } catch {
    dumpContainerState(
      `[${phase}] the palette hit for ${FILE_NAME} carried no <mark> -- the index's snippet markers did not`
      + ' reach the rendered hit, so a result shows what matched but never why',
    );
    throw Object.assign(new Error('the palette hit rendered no highlighted snippet'), { dumped: true });
  }

  const markedText = ((await marked.first().textContent()) ?? '').trim().toLowerCase();

  if (! markedText.includes(FILE_MARKER.toLowerCase())) {
    dumpContainerState(
      `[${phase}] the palette highlighted "${markedText}" rather than the searched word ${FILE_MARKER}`,
    );
    throw Object.assign(new Error('the palette highlighted a word other than the one searched for'), { dumped: true });
  }

  // Named for what it proves. The SWAP is proved: the index's private-use
  // markers reached the rendered hit and became a <mark> around the word that
  // matched. The ESCAPE half -- that the view escapes the passage BEFORE
  // swapping, which is the security property the Blade comment is about -- is
  // NOT proved here, and saying "the escape-then-swap ran" would have claimed
  // it. This smoke's fixture body carries no '<', '&' or quote, so a view that
  // swapped first, or never escaped at all, renders identically. Proving the
  // order needs a document with markup in its text asserted to arrive as
  // literal characters; that is issue #279, not this check.
  console.log(`[${phase}] the hit's snippet wraps ${FILE_MARKER} in a <mark> -- the index's markers reached the rendered hit`);

  // Read the destination off the hit BEFORE pressing anything, and then
  // require that exact URL.
  //
  // The first version of this assertion waited for any /files/<id> carrying a
  // `file` query parameter. That passes today only because exactly one hit
  // exists, and it is the shape decisions 0096-0099 were written about: a
  // check reporting on something other than what it appears to. Add a second
  // document matching the marker and Enter could open the WRONG hit with the
  // assertion none the wiser, because "some file preview opened" was all it
  // ever required. Pinning to this hit's own href also pins selected === 0 to
  // the hit the viewer can see at the top of the list, which is the thing the
  // keyboard contract actually promises.
  const href = await hit.first().getAttribute('href');

  if (href === null || href === '') {
    dumpContainerState(`[${phase}] the palette hit for ${FILE_NAME} carried no href, so destinationFor() produced nothing`);
    throw Object.assign(new Error('the palette hit rendered without a destination'), { dumped: true });
  }

  const destination = new URL(href, BASE_URL);
  const expected = destination.pathname + destination.search;

  // What destinationFor() promises for a file hit: its directory's listing,
  // with the file named so the preview opens. Asserted on the rendered href
  // rather than on wherever the browser ends up, so a navigation to the right
  // URL for the wrong reason cannot satisfy it.
  if (! /^\/files\/\d+$/.test(destination.pathname) || destination.searchParams.get('file') === null) {
    dumpContainerState(`[${phase}] the palette hit for ${FILE_NAME} points at ${expected}, which is not a file preview URL`);
    throw Object.assign(new Error('the palette hit points somewhere other than its file'), { dumped: true });
  }

  // Enter, not a click. openSelected() lives entirely in the root element's
  // x-data and clicks whatever carries data-selected="true"; selected starts
  // at 0, so the first hit is the one that opens. Clicking the anchor directly
  // would prove the href and skip every line of that x-data, which is the part
  // no other test in this repository executes.
  await input.press('Enter');

  try {
    await page.waitForURL((u) => u.pathname + u.search === expected, { timeout: 15000 });
  } catch {
    dumpContainerState(
      `[${phase}] Enter on the palette did not navigate to the selected hit -- expected ${expected}, the URL is`
      + ` ${page.url()}, so Alpine's openSelected() did not reach the selected anchor`,
    );
    throw Object.assign(new Error('Enter did not open the selected palette hit'), { dumped: true });
  }

  console.log(`[${phase}] Enter opened the selected hit at exactly its own href, ${expected} -- OK`);

  // The palette closes on its way out (x-on:click on the anchor calls
  // closePalette()), and wire:navigate replaced the page underneath it. Settle
  // before the last leg, for the reason decision/0033 records above.
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  // The OTHER way in, and the only one that isolates a single site.
  //
  // Ctrl-K above proves the palette opens from the keyboard and cannot say
  // WHICH of the two handlers opened it. The topbar's search field dispatches
  // open-search-palette from x-on:focus and does nothing else, so focusing it
  // exercises that CustomEvent and the palette's listener for it on their own
  // -- no keydown involved, neither Ctrl-K handler in the path. Without this
  // leg the listener is one of two possible causes of every open above and a
  // witness to none of them.
  //
  // Deliberately last, and deliberately not followed by an Escape. x-trap
  // returns focus to the element it was taken from when the dialog unmounts,
  // so closing the palette hands focus back to this very field, whose focus
  // handler opens it again: asserting "Escape closed it" here would be
  // asserting against a loop the product genuinely has. The next check opens
  // with its own page.goto(), which clears it.
  // Back to a clean /files first. Enter above left the browser on the file's
  // own URL with the preview dialog open, and that dialog's overlay sits over
  // the topbar -- a click aimed at the search field would land on the overlay
  // and this leg would fail for a reason that has nothing to do with the
  // palette. Re-waiting for the Alpine hint after the navigation, for the same
  // reason the first one is there.
  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page
    .locator('[data-test="search-shortcut-hint"]')
    .filter({ hasText: 'Ctrl K' })
    .waitFor({ state: 'attached', timeout: 15000 });

  await page.locator('[data-test="header-search"] input[name="q"]').click();

  try {
    await input.waitFor({ state: 'visible', timeout: 10000 });
  } catch {
    dumpContainerState(
      `[${phase}] focusing the topbar search field did not open the palette -- the open-search-palette`
      + ' CustomEvent or the palette\'s listener for it is not running in the image',
    );
    throw Object.assign(new Error('the topbar search field did not open the command palette'), { dumped: true });
  }

  console.log(`[${phase}] focusing the topbar field opens the palette too -- the open-search-palette listener ran on its own`);

  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
}

// item/reverse-proxy-ready (issue #58): proves bootstrap/app.php's
// TRUSTED_PROXIES default and ForceRootUrlFromRequest reach all the way to a
// password-reset link, not just a page rendered straight to a browser (the
// curl-based check in the workflow step covers that one).
//
// tinker() has no incoming HTTP request at all, so a plain `route(...)` call
// there would just fall back to config('app.url') regardless of whether
// TRUSTED_PROXIES or APP_URL are wired up correctly -- CLAUDE.md's mutation
// check for a security guard applies just as well to a done-when assertion:
// one that would pass even with the fix reverted is worse than none, because
// it looks like coverage. So this dispatches one synthetic request through
// the app's *real* HTTP kernel first -- the same TrustProxies configuration
// and the same ForceRootUrlFromRequest middleware a real reverse-proxied
// request goes through -- then generates the password-reset URL immediately
// afterwards, in the same PHP process, while the 'url' generator still has
// that request's trusted, forwarded scheme and host. Symfony's TrustProxies
// match is by REMOTE_ADDR, not by which route was requested, so it does not
// matter that the dispatched request targets /login rather than
// /forgot-password.
//
// If TRUSTED_PROXIES were not honoured, or APP_URL were not falling back to
// the request, this produces http://ignored.invalid/... instead: it fails
// without either fix landed, it does not just pass by construction.
function checkForwardedPasswordResetUrl() {
  if (!SMOKE_FORWARDED_HOST) {
    throw new Error('SMOKE_FORWARDED_HOST is required for checkForwardedPasswordResetUrl()');
  }

  const php = [
    '$kernel = app(\\Illuminate\\Contracts\\Http\\Kernel::class);',
    "$request = \\Illuminate\\Http\\Request::create('http://ignored.invalid/login', 'GET');",
    "$request->server->set('REMOTE_ADDR', '127.0.0.1');",
    "$request->headers->set('X-Forwarded-Proto', 'https');",
    `$request->headers->set('X-Forwarded-Host', '${SMOKE_FORWARDED_HOST}');`,
    '$kernel->handle($request);',
    "echo 'RESET_URL:' . route('password.reset', ['token' => 'smoke-token', 'email' => 'smoke@example.test']);",
  ].join(' ');

  const output = tinker(php);
  const match = /RESET_URL:(\S+)/.exec(output);

  if (!match) {
    dumpContainerState(`tinker produced no password-reset URL -- raw output: ${output}`);
    throw Object.assign(new Error('tinker produced no RESET_URL'), { dumped: true });
  }

  const url = match[1];
  const expectedPrefix = `https://${SMOKE_FORWARDED_HOST}/`;

  if (!url.startsWith(expectedPrefix)) {
    dumpContainerState(
      `password-reset URL "${url}" did not honour X-Forwarded-Proto/-Host (expected it to start with ${expectedPrefix})`,
    );
    throw Object.assign(new Error(`password-reset URL is not https on the forwarded host: ${url}`), { dumped: true });
  }

  console.log(`[setup] generated password-reset URL honours the forwarded host -- ${url}`);
}

// item/user-reset-password-command (issue #61), first half of its doneWhen:
// `doccum:user:reset-password` must print a one-time link that genuinely
// works with no mailer configured -- which is exactly this container's
// state, since the workflow's `docker run` for this job sets no MAIL_MAILER
// at all (config/mail.php falls back to "log").
//
// The account it resets is a throwaway created here, not the smoke admin:
// reusing the admin would change its password out from under the "verify"
// phase's later login, which authenticates with SMOKE_ADMIN_PASSWORD
// unchanged across the container replacement.
//
// User::create(), not User::factory(): fakerphp/faker backs fake() and is a
// require-dev dependency (composer.json), so it is not autoloadable at all
// in this --no-dev image even though the feature test suite can use it
// freely against the dev-installed vendor/ tree.
//
// The printed URL's host is NOT followed as printed. config('app.url')
// defaults to "http://localhost" (config/app.php) and this container sets
// no APP_URL, and App\Http\Middleware\ForceRootUrlFromRequest -- which
// would otherwise make a URL generated during a real HTTP request use the
// request's own host -- says plainly in its own docblock that it does
// nothing for "console commands, queue workers, the scheduler" on purpose,
// so a link printed by this command is generated exactly like it would be
// for any other operator who has not set APP_URL: "http://localhost/...",
// which this runner cannot dial (nothing answers on port 80). That is a
// real, reportable rough edge for an operator relying on the container's
// documented `docker run ... -p 8080:8080` invocation as-is -- see the
// task report -- but it is orthogonal to whether the TOKEN the command
// printed is genuine, which is what this check actually proves: it keeps
// the path and discards the host, then drives BASE_URL + that path.
//
// Mutation this is meant to prove load-bearing: in
// UserResetPassword::handle(), change `$token = $broker->createToken($user);`
// to append anything, e.g. `... . 'x';` -- the command still exits
// successfully and still prints a syntactically normal-looking
// /reset-password/... link (so a check that only asserted a successful
// exit code, or pattern-matched the URL shape, would keep passing), but the
// token no longer matches what the database actually stored. The reset
// form then redirects back to /reset-password with a validation error
// instead of accepting the new password, so the FIRST `waitForURL(pathname
// === '/login')` never resolves and this check fails on that timeout,
// before ever reaching the login step.
async function checkResetPasswordCommandPrintsAWorkingLink(browser, phase) {
  const digits = Date.now().toString().slice(-9);
  const email = `smoke-reset-${digits}@example.test`;
  const username = `smokereset${digits}`;
  const newPassword = `Doccum-Smoke-Reset-${digits}!Aa`;

  const createPhp = [
    "$u = \\App\\Models\\User::create(['name' => 'Smoke Reset Target',",
    `'username' => '${username}', 'email' => '${email}',`,
    "'password' => 'whatever-it-was-before']);",
    "echo 'CREATED:' . $u->email;",
  ].join(' ');

  const createOutput = tinker(createPhp);
  if (!createOutput.includes(`CREATED:${email}`)) {
    dumpContainerState(
      `[${phase}] could not create the scratch account for the reset-password command check -- raw output: ${createOutput}`,
    );
    throw Object.assign(new Error('scratch account creation for the reset-password command check failed'), { dumped: true });
  }

  let commandOutput;
  try {
    // The real CLI entrypoint an operator locked out with no mailer would
    // actually run -- not tinker -- so this also proves the artisan command
    // itself is registered and reachable inside the shipped image.
    commandOutput = execFileSync(
      'docker',
      ['exec', CONTAINER_NAME, 'php', 'artisan', 'doccum:user:reset-password', email],
      { encoding: 'utf8', timeout: 20000 },
    );
  } catch (e) {
    dumpContainerState(`[${phase}] doccum:user:reset-password failed for a real account -- ${e.message}`);
    throw Object.assign(new Error('doccum:user:reset-password failed for a real account'), { dumped: true });
  }

  const match = /\/reset-password\/(\S+?)(?=["'\s?]|$)/.exec(commandOutput);
  if (!match) {
    dumpContainerState(`[${phase}] doccum:user:reset-password printed no reset-password link -- raw output: ${commandOutput}`);
    throw Object.assign(new Error('doccum:user:reset-password printed no reset link'), { dumped: true });
  }
  const resetUrl = `${BASE_URL}/reset-password/${match[1]}`;
  console.log(`[${phase}] doccum:user:reset-password printed a link for ${email}`);

  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    await page.goto(resetUrl, { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(newPassword);
    await page.getByLabel('Confirm password', { exact: true }).fill(newPassword);
    await Promise.all([
      page.waitForURL((u) => u.pathname === '/login', { timeout: 10000 }),
      page.getByRole('button', { name: 'Reset password' }).click(),
    ]);
    console.log(`[${phase}] the printed link's token was accepted and set a new password`);

    // Landing on /login only proves the form posted -- logging in with the
    // password the link just set is what proves the link genuinely changed
    // it, per CLAUDE.md: prefer an assertion that requires the feature to
    // DO something over one that only observes a resting state.
    // Login here still uses EMAIL, not username -- this check is about the
    // reset-password link, not item/login-by-username, so it keeps using
    // the identifier it already had (the page's field label changed under
    // it -- see login.blade.php -- so only the selector below needed to
    // move; the value being an email still resolves through AuthenticateUser
    // the same way it always did).
    await page.getByLabel('Username or email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(newPassword);
    await Promise.all([
      page.waitForURL((u) => u.pathname !== '/login', { timeout: 15000 }),
      page.getByRole('button', { name: 'Log in' }).click(),
    ]);
    console.log(`[${phase}] logged in with the password doccum:user:reset-password set -- the link genuinely worked`);
  } finally {
    await context.close();
  }
}

// item/user-reset-password-command (issue #61), second half of its
// doneWhen: the forgot-password POST must say mail is not configured
// instead of claiming it emailed anything, when the mailer is "log" or
// "array" -- which, again, is this container's actual default (no
// MAIL_MAILER set at all). Feature tests already prove this against the
// test renderer; this proves it against the real routes, real session
// handling and real Blade rendering of the shipped image, which is the
// whole reason CLAUDE.md requires it here at all.
//
// The important half of this check is not "it says mail is not configured"
// -- it is that a real account and an address with NO account produce the
// byte-identical flashed message. A version of the fix that only patched
// the success branch would still let the failure branch's stock "we can't
// find a user with that email address" leak account existence straight
// back out, invisibly to a check that only asserted "each one is some kind
// of message".
//
// Runs in its own fresh, unauthenticated context: /forgot-password sits
// behind Fortify's `guest` middleware, and the admin page used by the rest
// of this script is already logged in, so reusing it would just redirect
// away before the form ever rendered.
//
// Mutation this is meant to prove load-bearing: in
// App\Http\Responses\Fortify\FailedPasswordResetLinkRequestResponse, delete
// the `if (MailDeliverability::unavailable()) { ... }` branch (leaving only
// the stock fallback). The "no account" call then falls through to
// Fortify's own stock failure response, which flashes a validation error
// on 'email' instead of the 'status' this page's only
// x-auth-session-status element renders -- so statusFor()'s second call
// never finds a ".text-green-600" node at all and this check fails on that
// wait's own timeout, never even reaching the equality comparison below
// it. Either failure mode -- a timeout because the two branches stopped
// rendering the same kind of thing, or a mismatch because they render two
// different somethings -- proves the same fact: the two calls stopped
// being interchangeable. A check that only asserted the real-account
// branch said "mail is not configured" would keep passing under that exact
// mutation, which is why comparing the two calls is the assertion, not
// either one alone.
async function checkForgotPasswordSameResponseRegardlessOfAccount(browser, phase) {
  const context = await browser.newContext();
  try {
    const page = await context.newPage();

    const statusFor = async (email) => {
      await page.goto(`${BASE_URL}/forgot-password`, { waitUntil: 'domcontentloaded' });
      await page.getByLabel('Email address', { exact: true }).fill(email);
      // Not paired with a waitForURL/waitForLoadState race the way other
      // submits in this script are: this POST redirects back to the SAME
      // path (back()->with('status', ...)), so the pathname never changes,
      // and calling waitForLoadState() concurrently with the click could
      // resolve against the page's PRE-click state instead of the
      // redirect's. Playwright's locator below already polls/retries
      // across the navigation on its own.
      await page.getByRole('button', { name: 'Email password reset link' }).click();
      // x-auth-session-status's only styling hook -- see
      // resources/views/components/auth-session-status.blade.php -- and the
      // only element on this page that carries it.
      const status = page.locator('.text-green-600').first();
      await status.waitFor({ state: 'visible', timeout: 10000 });
      return (await status.innerText()).trim();
    };

    const forRealAccount = await statusFor(ADMIN_EMAIL);
    const forNoAccount = await statusFor('no-such-account-at-all@example.invalid');

    if (!forRealAccount.includes('Mail is not configured')) {
      dumpContainerState(
        `[${phase}] forgot-password did not say mail is not configured for a real account -- got: "${forRealAccount}"`,
      );
      throw Object.assign(new Error('forgot-password claimed to send mail with no mailer configured'), { dumped: true });
    }

    if (forRealAccount !== forNoAccount) {
      dumpContainerState(
        `[${phase}] forgot-password gave different responses for a real account and a nonexistent one\n` +
          `real account: "${forRealAccount}"\nno account:   "${forNoAccount}"`,
      );
      throw Object.assign(new Error('forgot-password response differs by whether the account exists'), { dumped: true });
    }

    console.log(
      `[${phase}] forgot-password says mail is not configured, byte-identically, for a real account and for no account at all`,
    );
  } finally {
    await context.close();
  }
}

/**
 * Drives the topbar shell (item/topbar-shell, issue #12) in the real
 * container, because the layout test that shipped it renders Blade through
 * the test renderer and cannot see the image at all.
 *
 * What each assertion is actually worth, measured rather than assumed. The
 * whole function was mutation-checked by removing @fluxScripts from the
 * layout and running the smoke against the resulting image:
 * https://github.com/turbophp/doccum/actions/runs/35223275581
 *
 *   - "the account menu opens on click" is the LOAD-BEARING assertion. With
 *     @fluxScripts gone it timed out here, so it genuinely proves the
 *     scripts loaded and booted in the image. A broken asset build leaves
 *     every Blade assertion green while logout is unreachable in a browser.
 *
 *   - "the menu is hidden before the click" proves NOTHING on its own, and
 *     the earlier claim that it did was wrong. In that same mutated image,
 *     with no JavaScript at all, the menu was still hidden -- so this check
 *     passes on a container shipping no scripts. It is kept only as a cheap
 *     sanity check that the click is what changes the state, and must never
 *     be cited as evidence the page is alive.
 *
 *   - The version pill check is now a genuine cross-source comparison
 *     (item/version-from-tag). tinker reads config('doccum.version') from
 *     the PHP process; `docker inspect` reads org.opencontainers.image.version
 *     off the image's own metadata, set by the Dockerfile's DOCCUM_VERSION
 *     ARG/LABEL entirely independently of anything Laravel resolves at
 *     runtime. The check requires pill == label == config, so it fails
 *     whenever any one of those three disagrees with the other two -- not
 *     just when the Blade renders a hardcoded literal.
 *
 *     BE PRECISE ABOUT WHAT THIS DOES AND DOES NOT CATCH, because the phrase
 *     "cross-source" flatters it. The label and the env var both come from
 *     the SAME `ARG DOCCUM_VERSION` at build time, so a single wrong
 *     --build-arg sets both of them wrongly and identically, and this check
 *     passes. What it does catch is a runtime value that has drifted from
 *     what the image says it is -- a container started with DOCCUM_VERSION
 *     overridden, a Blade literal, an ENV that never reaches config() -- and
 *     that is a real class of defect, but it is NOT "the image was built
 *     from the tag it claims".
 *
 *     Nothing here can prove that half: no tag exists in this job, and the
 *     only independent witness to it is the git ref the release ran from.
 *     item/release-v0-1-0 owns it, by cutting a throwaway pre-release tag
 *     and reading the label off the published image -- release.yml has never
 *     run at all (decision/0075), so that path is entirely unexercised.
 */
async function checkTopbar(page, phase) {
  const version = versionFromContainer();
  const label = versionLabelFromContainer();

  const pill = page.locator('[data-test="version-pill"]');
  await pill.waitFor({ state: 'visible', timeout: 10000 });
  const pillText = (await pill.innerText()).trim();
  if (pillText !== `v${version}` || label !== version) {
    throw new Error(
      `version mismatch across sources -- pill: "${pillText}", ` +
        `config('doccum.version'): "${version}", ` +
        `org.opencontainers.image.version label: "${label}"`,
    );
  }
  console.log(`[${phase}] version pill, config('doccum.version') and the image label all agree: ${pillText}`);

  // What is asserted here is that the topbar renders at all outside the test
  // renderer, with Flux's own components resolving in the image.
  //
  // Home and Files only: Settings moved into the account menu, so it is
  // deliberately NOT visible at rest and is asserted below, once that menu is
  // open. A plain member never seeing Settings at all is covered by the layout
  // test.
  for (const nav of ['nav-home', 'nav-files']) {
    await page.locator(`[data-test="${nav}"]`).waitFor({ state: 'visible', timeout: 10000 });
  }
  console.log(`[${phase}] topbar shows Home and Files for the administrator`);

  // Cheap sanity check only -- see the docblock. This passes with no JS in
  // the image at all, so it is evidence that the click changes something,
  // never evidence that the page is alive.
  const logout = page.locator('[data-test="logout-button"]');
  if (await logout.isVisible()) {
    throw new Error('the account menu was already visible before its trigger was clicked');
  }

  await page.locator('[data-test="account-menu-trigger"]').click();
  await logout.waitFor({ state: 'visible', timeout: 10000 });
  console.log(`[${phase}] account menu opens on click -- scripts booted in the image`);

  // The administrator holds properties.manage, so Settings is expected -- in
  // the account menu now rather than the primary nav. Asserting it here keeps
  // what the old check proved (the destination renders in the image for an
  // administrator) and adds what the move introduced: that it is reachable
  // once the menu is open.
  await page
    .locator('[data-test="nav-settings"]')
    .waitFor({ state: 'visible', timeout: 10000 });
  console.log(`[${phase}] Settings is in the account menu for the administrator`);

  // item/admin-roles (issue #19): the administrator this smoke runs as holds
  // EVERY permission, which is the case the product was broken in. While the
  // Settings entries were mutually exclusive, properties.manage won and this
  // account -- the only administrator a shipped instance has -- had no link to
  // Users or Roles at all. The pages were reachable only by typing the URL,
  // which is exactly what every feature test and every other check in this
  // script does, so nothing caught it.
  //
  // Asserting the OTHER sections here is therefore not redundant with the
  // check above: nav-settings alone was visible throughout the defect.
  // item/admin-periods (issue #20): the SAME defect shape, once more -- see
  // the note above. periods.manage is not held by users.manage or
  // properties.manage, so an @elsecan chained onto either of those would
  // have hidden this entry from the very administrator this smoke runs as.
  // item/admin-instance-settings (issue #21): same reasoning again --
  // gated on users.manage, same as Users and Roles above, so it would
  // already be reachable through that block alone, but its own independent
  // @can block (never @elsecan) keeps that true even if a future change
  // narrows one of these permissions on its own.
  for (const [section, testId] of [['Users', 'nav-settings-users'], ['Roles', 'nav-settings-roles'], ['Archive periods', 'nav-settings-periods'], ['Instance settings', 'nav-settings-instance']]) {
    try {
      await page.locator(`[data-test="${testId}"]`).waitFor({ state: 'visible', timeout: 10000 });
    } catch {
      dumpContainerState(
        `[${phase}] the ${section} section is missing from the account menu for an administrator holding every`
        + ` permission -- [data-test="${testId}"] never became visible, so that page is unreachable in the product`,
      );
      throw Object.assign(
        new Error(`${section} is not reachable from the account menu for a full administrator`),
        { dumped: true },
      );
    }
  }
  console.log(`[${phase}] Users, Roles, Archive periods and Instance settings are reachable from the account menu too -- OK`);

  return logout;
}

/**
 * Opens a file in the preview dialog and requires it to SHOW something.
 *
 * What makes this worth running rather than decorative: it asserts the preview
 * has actually rendered the file's bytes, not merely that a dialog appeared.
 * An element exists whether or not what it was supposed to show arrived, so
 * "the dialog opened" is satisfied by a preview showing nothing -- which is
 * exactly the state a file with no stored version, or a frame pointing at a
 * 404, was in before this branch. The assertion requires the uploaded text to
 * be inside what was drawn.
 *
 * Also the one check that drives an icon action: every control on a row is
 * icon-only now, named by aria-label, so this is where "the icons reached
 * their methods in the built image" is established.
 *
 * Mutation, measured rather than argued -- with the caveat stated plainly,
 * because CLAUDE.md says never to cite an unmutated assertion as evidence and
 * a half-measured one deserves the same honesty. Replacing the text/pdf branch
 * of the preview with `@elseif (false)` and running this assertion's exact
 * locators produced:
 *
 *     guard present -> pass: frame contains the file bytes
 *     guard deleted -> fail: locator.waitFor: Timeout 8000ms exceeded
 *     restored      -> pass
 *
 * Re-measured after the assertion moved from the frame to the rendered source,
 * because a mutation proves the assertion it was run against and not its
 * successor: emptying the code view (dropping x-html="code") made it fail at
 * the timeout, and restoring it made it pass.
 *
 * The original measurement, kept because what the container caught is the
 * reason this check exists. That was run against the dev server first, and the
 * container then found something the dev server could not: the preview pointed at the DOWNLOAD
 * route, which answers Content-Disposition: attachment and, where object
 * storage can issue one, redirects to a presigned URL. On a dev machine that
 * redirect lands on Laravel's own /storage path and renders; in the image it
 * names MinIO on loopback:9000, which the browser on :8080 cannot reach, so
 * the frame stayed blank and this assertion timed out at 15s.
 *
 * That is the check doing exactly the job CLAUDE.md keeps it for -- a green
 * suite and a passing dev-server run both said the preview worked. The fix is
 * FilePreviewController, which always streams and always inline.
 */
async function checkFilePreviewShowsTheFileContents(page, phase) {
  const name = 'DoccumSmokePreviewTarget.txt';
  const body = 'These exact words must appear inside the preview frame.\n';

  await uploadAndProveStored(page, name, body, phase);

  const row = page.locator('tr[data-test="file-row"]').filter({ hasText: name });
  await row.getByLabel('View', { exact: true }).click();

  const dialog = page.locator('[data-test="preview-modal"]');
  await dialog.waitFor({ state: 'visible', timeout: 10000 });

  // The header names the file being shown, which is what tells someone with
  // two previews open in sequence which one they are looking at.
  await dialog.getByText(name, { exact: true }).waitFor({ timeout: 10000 });

  // A .txt renders as highlighted SOURCE, not in an iframe: the preview shows
  // markup in a frame and everything else textual as its own text, so
  // [data-test="preview-frame"] does not exist for this file at all. This
  // assertion used to wait for that frame and timed out at 15s once the code
  // view landed -- the check outliving the shape of the thing it checks.
  //
  // What it requires is unchanged and is the point: the uploaded bytes have to
  // be INSIDE what was rendered. "A dialog appeared" is satisfied by a preview
  // showing nothing at all.
  const rendered = page.locator('[data-test="preview-code"]');
  await rendered.waitFor({ state: 'visible', timeout: 15000 });
  await rendered.filter({ hasText: 'must appear inside the preview frame' })
    .waitFor({ timeout: 15000 });

  console.log(`[${phase}] the preview rendered the uploaded file's own bytes`);

  await page.keyboard.press('Escape');
  await dialog.waitFor({ state: 'hidden', timeout: 10000 });
  console.log(`[${phase}] Escape closes the preview`);
}

/**
 * item/home-dashboard (issue #16): confirms the Home destination (spec §10)
 * is wired to real data, not a static placeholder -- the starter kit's own
 * `dashboard` view before this item, which rendered three empty tiles no
 * matter what the database held. FILE_NAME, uploaded by uploadAndProveStored()
 * moments before this is called, must show up by name in Home's "Recent
 * files" section.
 *
 * This is the one assertion that requires the feature to DO something, not
 * merely observe a resting state (CLAUDE.md's own topbar lesson): an empty
 * "Recent files" section, or its absence, or Home simply 200-ing, would all
 * pass a check that only asked "did the page load". A query that forgot to
 * filter through DirectoryAccess correctly and came back empty, one wired to
 * the wrong column so nothing ever matches, or a Flux component
 * (Home/Index.php's own view) that fails to resolve in the image, would
 * each leave this empty or absent, and only requiring THIS upload's OWN name
 * to appear is what turns that into a failure here rather than a green run
 * that never looked.
 */
async function checkHomeDashboardShowsRecentUpload(page, phase) {
  await page.goto(`${BASE_URL}/dashboard`, { waitUntil: 'domcontentloaded' });

  await page.locator('[data-test="home-recent-files"]').waitFor({ state: 'visible', timeout: 10000 });

  // Named failure, not a bare locator timeout. The mutation for this item
  // (recents resolved but never rendered) failed here with nothing but
  // "locator.waitFor: Timeout 10000ms exceeded" -- attributable only because
  // this check's own console line happened to be the last thing printed,
  // which is luck rather than evidence. Rule 3 of the Mutation vocabulary
  // says a failure that names no assertion is not evidence, and every other
  // check in this file already says what it wanted.
  const row = page.locator('[data-test="home-recent-file-row"]').filter({ hasText: FILE_NAME });

  try {
    await row.waitFor({ timeout: 10000 });
  } catch {
    dumpContainerState(
      `[${phase}] Home rendered but listed no recent-files row for ${FILE_NAME}`
      + ' -- the page is up and the query may well be right; what is missing is'
      + ' the row reaching the DOM',
    );
    throw Object.assign(
      new Error(`Home listed no recent-files row for ${FILE_NAME}`),
      { dumped: true },
    );
  }

  console.log(`[${phase}] Home lists ${FILE_NAME} under "Recent files" -- real data, not a placeholder`);
}

/**
 * item/files-three-pane (issue #104/#99): creates two nested subdirectories
 * inside the directory the page is currently showing, navigates into both,
 * and counts the breadcrumb. tests/Feature/FileBrowserTest.php already
 * proves Browser::breadcrumbTrail() resolves the right ANCESTOR IDS against
 * the test renderer -- what only a real browser against the built image can
 * see is whether flux:breadcrumbs actually renders every one of them, and
 * whether the sidebar's own reach-root links (resources/views/livewire/files/
 * partials/directory-tree.blade.php) are real, clickable <a> elements once
 * Flux and Livewire's wire:navigate have booted in the image.
 *
 * Two levels deep from the starting directory is exactly THREE breadcrumb
 * items -- the starting directory, the first subdirectory, and the second,
 * root first (Browser::breadcrumbTrail() puts the current directory last) --
 * so counting [data-test="breadcrumb-item"] is the assertion, not merely
 * seeing the current directory's own name the way the breadcrumb used to
 * show before this item.
 *
 * No retry here, matching the rest of this file: each step is a single
 * Livewire round trip with its own bounded wait, not a loop that would mask
 * a genuine failure the way issue #106 was masked before its retry was
 * removed.
 */
async function checkBreadcrumbNavigatesTwoLevels(page, phase) {
  const level1 = `DoccumSmokeTreeLevel1-${Date.now()}`;
  const level2 = `DoccumSmokeTreeLevel2-${Date.now()}`;

  // Scoped to '[data-test="directories-list"]' (the centre pane), never a
  // bare locator: the sidebar renders the SAME directory, nested under the
  // one being browsed, the moment it exists (issue #99's whole point), so
  // an unscoped getByText()/getByRole() here matches twice and Playwright's
  // strict mode refuses to guess which one this means.
  const list = page.locator('[data-test="directories-list"]');

  await openNewFolderDialog(page);
  await page.getByLabel('Folder name', { exact: true }).fill(level1);
  await Promise.all([
    list.getByText(level1, { exact: true }).waitFor({ timeout: 10000 }),
    page.getByRole('button', { name: 'Create', exact: true }).click(),
  ]);

  // Waits for the centre pane to stop listing level1, because a directory is
  // not among its OWN children -- so this is true only once the navigation
  // has actually landed.
  //
  // It used to wait for the 'New folder' field to be visible, and that field
  // is on the root page too. The wait was therefore satisfied instantly by
  // the page being left, before wire:navigate swapped the DOM, and the
  // fill() below wrote into a field about to be destroyed: level2 was never
  // created inside level1 and the next wait timed out. Deterministically, on
  // three runs, including the correct build -- which is how it was caught
  // rather than shipped.
  //
  // That is CLAUDE.md's topbar lesson in a different costume: a signal that
  // is already true before the action proves nothing about the action. The
  // comment this replaces even said it was waiting on "the NEXT page's own
  // content"; the intent was right and the chosen signal did not
  // discriminate.
  //
  // Deliberately NOT the breadcrumb, which is what this check exists to
  // test: waiting on the thing under test would make a breadcrumb defect
  // surface here, as an opaque navigation timeout, instead of at the count
  // assertion below where it is named.
  await list.getByRole('link', { name: level1, exact: true }).click();
  await list.getByText(level1, { exact: true }).waitFor({ state: 'detached', timeout: 10000 });

  await openNewFolderDialog(page);
  await page.getByLabel('Folder name', { exact: true }).fill(level2);
  await Promise.all([
    list.getByText(level2, { exact: true }).waitFor({ timeout: 10000 }),
    page.getByRole('button', { name: 'Create', exact: true }).click(),
  ]);

  // Same discriminating wait as above, and for the same reason: the count
  // assertion below must be what fails when the breadcrumb is wrong, not a
  // navigation wait that happens to depend on it.
  await list.getByRole('link', { name: level2, exact: true }).click();
  await list.getByText(level2, { exact: true }).waitFor({ state: 'detached', timeout: 10000 });

  // Waits for THIS crumb specifically, not merely "a last breadcrumb item is
  // visible" -- the previous page (level1's) already had one of those
  // before this click, so that alone would prove nothing about whether the
  // navigation actually landed. hasText scopes to the one breadcrumb item
  // reading level2's own name (there is exactly one: the sidebar's own
  // link for it, expanded by default, carries no data-test="breadcrumb-item").
  const crumbs = page.locator('[data-test="breadcrumb-item"]');
  await page.locator('[data-test="breadcrumb-item"]', { hasText: level2 }).waitFor({ state: 'visible', timeout: 10000 });
  const count = await crumbs.count();

  if (count !== 3) {
    throw new Error(
      `expected 3 breadcrumb items two levels into the tree (root, ${level1}, ${level2}), got ${count}`,
    );
  }

  console.log(`[${phase}] breadcrumb shows all 3 ancestors two levels into the tree`);

  // Back to the starting directory via the sidebar's own reach-root link --
  // not a page.goto(), because reaching it that way is the point: this
  // proves the sidebar's tree link is a real, clickable one, not only that
  // the breadcrumb rendered.
  // Waits for level1 to be back in the CENTRE pane, which is true only at
  // the starting directory: level1 is its child, and level2's page lists
  // level2's children instead.
  //
  // This wait used to be 'New folder' visible, and that field is on every
  // directory page, so it was satisfied instantly by the page being left --
  // the same non-discriminating signal as the two navigations above, and it
  // did not merely fail this check, it LEAKED. The upload-guard check runs
  // next; it began on the outgoing page, its upload fired, and wire:navigate
  // then swapped in a fresh Upload button with no upload in flight, which
  // reads enabled. So a wait that does not discriminate here reports as
  // "issue #106 is reachable again" two checks later, blaming a guard that
  // is present and correct.
  //
  // Every page transition in this function now waits on something true only
  // on the destination. That is the rule, not a patch: a check that leaves
  // the browser mid-navigation hands its own failure to whatever runs next.
  await page.locator('[data-test="directory-tree"]').getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await list.getByText(level1, { exact: true }).waitFor({ state: 'visible', timeout: 10000 });
}

/**
 * The embedded SQLite database's pragmas, read out of the RUNNING container.
 *
 * Four processes write to that one file -- FrankenPHP, two queue workers and
 * the scheduler -- with queue, cache and session all on the database driver.
 * SQLite's own default busy timeout is zero, so the loser of a write race
 * fails instantly instead of waiting, and that is what printed
 *
 *   General error: 5 database is locked
 *   (SQL: update "jobs" set "reserved_at" = ..., "attempts" = 1 ...)
 *
 * on main (issue #92). tests/Feature/SqlitePragmaTest.php asserts the same
 * two values, but it does so against a temporary file it configures itself,
 * because the suite's own connection is ":memory:" where journal_mode is
 * always "memory". Only this check reads them off the real /data database in
 * the image the operator actually runs, which is the configuration that was
 * wrong. It fails if config/database.php goes back to leaving either at the
 * driver default -- that is the mutation, and .github/mutations.json runs it.
 */
function checkEmbeddedSqlitePragmas() {
  const php = [
    "$journal = \\Illuminate\\Support\\Facades\\DB::select('pragma journal_mode')[0]->journal_mode;",
    // `pragma busy_timeout` answers in a column called `timeout`, not
    // `busy_timeout`.
    "$busy = \\Illuminate\\Support\\Facades\\DB::select('pragma busy_timeout')[0]->timeout;",
    // transaction_mode is not a SQLite pragma -- it is a Laravel connector
    // setting that decides whether a transaction opens BEGIN or BEGIN
    // IMMEDIATE -- so it is read from config rather than from the database.
    // It is reported here because busy_timeout above is worthless without it
    // (issue #121) and a silent revert to DEFERRED would otherwise leave two
    // correct-looking values and a lock that still happens.
    "$mode = config('database.connections.sqlite.transaction_mode');",
    "echo 'JOURNAL:' . $journal . ' BUSY:' . $busy . ' TXMODE:' . $mode;",
  ].join(' ');

  const output = tinker(php);
  const journal = /JOURNAL:(\S+)/.exec(output);
  const busy = /BUSY:(\d+)/.exec(output);

  if (!journal || !busy) {
    throw new Error(`could not read the SQLite pragmas from the container: ${output.trim()}`);
  }

  if (journal[1].toLowerCase() !== 'wal') {
    throw new Error(
      `the embedded database is journalling in "${journal[1]}", not WAL -- readers will block writers (issue #92)`,
    );
  }

  if (Number(busy[1]) <= 0) {
    throw new Error(
      `the embedded database's busy_timeout is ${busy[1]} -- a writer that loses a race fails instead of waiting (issue #92)`,
    );
  }

  const txMode = /TXMODE:(\S+)/.exec(output);

  if (! txMode || txMode[1].toUpperCase() !== 'IMMEDIATE') {
    throw new Error(
      `the embedded database's transaction_mode is "${txMode ? txMode[1] : '(unset)'}", expected IMMEDIATE --` +
      ' a DEFERRED transaction takes no lock at BEGIN, so SQLite refuses a read-then-write outright' +
      ' rather than letting busy_timeout wait on it (issue #121)',
    );
  }

  console.log(`[setup] embedded database: journal_mode=${journal[1]}, busy_timeout=${busy[1]}ms, transaction_mode=${txMode[1]}`);
}

/**
 * item/files-actions-ui (issue #101): drives the Trash control this item
 * wires into the file detail panel and proves it does what trashing a file
 * is supposed to do -- the file leaves the directory listing AND stops
 * being findable by search -- against the real container, not the test
 * renderer. tests/Feature/FileBrowserActionsTest.php already covers the
 * authorisation this control gates on; what only a browser against the real
 * image can see is whether the wire:click reaches TrashFile at all.
 *
 * Uploads its OWN file (TRASH_CHECK_FILE_NAME) rather than reusing
 * FILE_NAME/FILE_MARKER: those two are what the 'verify' phase re-checks
 * after the container is replaced, to prove upload and search survived --
 * trashing that same file here would break that unrelated check for a
 * reason that has nothing to do with persistence.
 *
 * Searches by the uploaded name rather than a marker word inside its body:
 * SearchIndexer::forFile() puts a file's name in `title`, indexed as soon as
 * the queued ReindexSearchDocument job runs (see SearchProjectionObserver),
 * independently of ExtractText finishing -- so this needs no
 * waitForExtraction()-style poll of its own, only a short retry for that one
 * queued reindex job to land.
 *
 * Selecting the file's name link uses page.getByText() rather than
 * page.getByRole('link', ...): unlike the folder link this script clicks
 * elsewhere, the file name has no href (it only carries wire:click, see
 * resources/views/livewire/files/browser.blade.php), so the <a> Flux renders
 * for it exposes no accessible "link" role at all -- only a real href does.
 *
 * The Trash button carries wire:confirm, a native browser confirm() dialog
 * (see resources/views/livewire/files/browser.blade.php and Livewire's own
 * wire-confirm docs) -- page.once('dialog', ...) below accepts it, since an
 * unhandled confirm() otherwise blocks the click forever.
 *
 * Mutation this is meant to prove load-bearing: empty out
 * TrashFile::handle()'s body (app/Actions/Files/TrashFile.php) so the
 * button click still succeeds and the panel still closes, but nothing is
 * actually soft-deleted. Recorded run against that mutated image:
 * https://github.com/turbophp/doccum/actions/runs/35259216429/job/105330423553
 * (pull request 111, opened as a draft purely to obtain that job and closed
 * immediately after -- tests.yml runs on pull_request and pushes to main
 * only, so a bare mutation-branch push produces no image job at all and
 * reports nothing, which is a trap worth knowing about).
 *
 * It failed where it had to, and the call log is the proof rather than the
 * red mark:
 *
 *   locator.waitFor: Timeout 10000ms exceeded.
 *   Call log:
 *     - waiting for getByText('DoccumSmokeTrashTarget.txt', { exact: true })
 *       to be detached
 *       25 x locator resolved to visible <a ...>DoccumSmokeTrashTarget.txt</a>
 *
 * The run reached the trash step, uploaded and confirmed the target, found it
 * in search, clicked Trash -- and the file stayed in the listing. A red image
 * job on its own would not have shown that: the run could have died earlier,
 * at the upload race in issue 106, with this assertion never executing.
 */
/**
 * Issue #106's guard, asserted directly rather than hoped for.
 *
 * The bug was a race: a file input posts its bytes the moment it changes, and
 * clicking Upload before that POST answers dispatches store() against a
 * property Livewire has not populated yet, which fails validation and is then
 * re-rendered over by the _finishUpload commit. The fix disables the submit
 * button for the whole upload, so the racing click cannot be made.
 *
 * Proving that needs the window held open, because in this container it is
 * about eighteen milliseconds wide -- far too narrow to observe by timing.
 * So the upload endpoint is stalled deliberately and the button inspected
 * while it hangs.
 *
 * The load-bearing assertion is the DISABLED one: with the wire:loading
 * attributes removed from browser.blade.php the button stays enabled through
 * the stall and this fails. The enabled-before and re-enabled-after
 * assertions are NOT evidence of the guard on their own -- a button that is
 * simply always enabled passes the first, and CLAUDE.md is explicit that a
 * resting state proves nothing. They are here as controls: the first shows
 * the button is not disabled for some unrelated reason, and the last shows
 * the guard releases, since a guard that wedges the button shut forever would
 * also satisfy the disabled assertion while breaking every upload.
 *
 * No file is created: the page is reloaded rather than the upload completed,
 * because checkBulkTrashLeavesUnselectedFilesAlone() later asserts the
 * listing holds only its survivor and an extra row here would break it.
 *
 * MUTATION RECORD, both directions measured against a built image:
 *
 *   correct build -> image PASSES
 *     https://github.com/turbophp/doccum/actions/runs/35291038177/job/105433709149
 *     [setup] the Upload button is disabled while the upload is in flight
 *
 *   wire:loading.attr="disabled" wire:target="upload" deleted from the Upload
 *   button, Replace's guard left intact -> image FAILS, here and nowhere else
 *     https://github.com/turbophp/doccum/actions/runs/35291156937/job/105433912606
 *     the Upload button stayed ENABLED while the upload endpoint was stalled
 *
 * Note which half moved. "[setup] the Upload button starts enabled" printed on
 * the MUTATED image too, so the enabled-before assertion is worth nothing on
 * its own -- exactly the topbar lesson CLAUDE.md records, measured again rather
 * than assumed. Only the disabled assertion changed.
 *
 * This is also the answer to the forwarding question that made data-test
 * unusable on flux:input: deleting these attributes changed the shipped
 * image's behaviour, so Flux does forward them to the real <button>.
 */
async function checkUploadButtonIsDisabledWhileTheFileIsStillUploading(page, phase) {
  const name = 'DoccumSmokeUploadRaceProbe.txt';
  const tmpFile = path.join(os.tmpdir(), name);
  fs.writeFileSync(tmpFile, 'Never submitted. This file exists only to open an upload window.\n');

  await openUploadDialog(page);

  const button = page.getByRole('button', { name: 'Upload', exact: true });

  if (await button.isDisabled()) {
    throw new Error('the Upload button was already disabled before any file was chosen');
  }
  console.log(`[${phase}] the Upload button starts enabled (control, not evidence)`);

  let release = () => {};
  const held = new Promise((resolve) => { release = resolve; });
  let stalled = false;

  // Matched by SUFFIX, never by a guessed prefix. Livewire's endpoints carry
  // a per-install hash -- this run's were under /livewire-a49e10a7/ -- and
  // hardcoding "/livewire/upload-file" is the exact mistake run/0014 records,
  // where a wait for an endpoint that never existed became the failure it was
  // written to observe.
  await page.route('**/upload-file*', async (route) => {
    stalled = true;
    await held;
    await route.continue();
  });

  try {
    await page.locator('[data-test="upload-form"] input[type="file"]').setInputFiles(tmpFile);

    // The stall only starts once the browser actually reaches the upload
    // endpoint, so wait for the interception before judging the button --
    // otherwise a fast enough machine could sample it before the upload
    // begins and read "enabled" as a failure of the guard.
    for (let waited = 0; ! stalled && waited < 10000; waited += 100) {
      await sleep(100);
    }

    if (! stalled) {
      throw new Error('no upload request reached the upload endpoint within 10s -- the file input never uploaded');
    }

    let disabled = false;
    for (let waited = 0; ! disabled && waited < 10000; waited += 100) {
      disabled = await button.isDisabled();
      if (! disabled) {
        await sleep(100);
      }
    }

    if (! disabled) {
      throw new Error(
        'the Upload button stayed ENABLED while the upload endpoint was stalled -- '
        + 'issue #106 is reachable again: either wire:loading.attr="disabled" '
        + 'wire:target="upload" is gone from browser.blade.php, or Flux is no longer '
        + 'forwarding those attributes to the real <button>',
      );
    }
    console.log(`[${phase}] the Upload button is disabled while the upload is in flight -- issue #106's race cannot be clicked`);
  } finally {
    release();
    await page.unroute('**/upload-file*');
  }

  let reEnabled = false;
  for (let waited = 0; ! reEnabled && waited < 10000; waited += 100) {
    reEnabled = await button.isEnabled();
    if (! reEnabled) {
      await sleep(100);
    }
  }

  if (! reEnabled) {
    throw new Error('the Upload button never re-enabled after the upload finished -- the guard wedges it shut');
  }
  console.log(`[${phase}] the Upload button re-enables once the upload lands (control, not evidence)`);

  // Discards the temporary upload without storing anything.
  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="directories-list"]').getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.locator('[data-test="upload-form"] input[type="file"]').waitFor({ state: 'attached', timeout: 10000 });
}

async function checkTrashRemovesFileFromListingAndSearch(page, phase) {
  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="directories-list"]').getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.locator('[data-test="upload-form"] input[type="file"]').waitFor({ state: 'attached', timeout: 10000 });

  // Through uploadAndProveStored(), not by hand. This check originally
  // repeated the old text-only assertion -- getByText(name) and nothing more
  // -- which is precisely what #107 had already replaced for the first
  // upload, and it was fooled in exactly the same way: it announced the file
  // as uploaded while the container reported FILE:no, and the run then died
  // twenty seconds later in a search for something that had never existed.
  await uploadAndProveStored(
    page,
    TRASH_CHECK_FILE_NAME,
    'Uploaded only to prove the Trash control removes a file. Its content is unused.\n',
    phase,
  );

  await searchUntilFoundByName(page, TRASH_CHECK_FILE_NAME);
  console.log(`[${phase}] search finds ${TRASH_CHECK_FILE_NAME} before it is trashed`);

  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="directories-list"]').getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.getByText(TRASH_CHECK_FILE_NAME, { exact: true }).waitFor({ timeout: 10000 });

  // Through the row, not the name. Clicking a file's NAME opens it in the
  // preview dialog now, and this check wants the detail panel BEHIND that
  // dialog: with the preview open, trash-file-button resolves but never
  // becomes actionable, because a scrim is over it. Selecting exactly one row
  // populates the same panel without opening anything.
  await clickFileRow(page, TRASH_CHECK_FILE_NAME);

  const trashButton = page.locator('[data-test="trash-file-button"]');
  await trashButton.waitFor({ state: 'visible', timeout: 10000 });

  page.once('dialog', (dialog) => dialog.accept());
  await trashButton.click();

  // The listing is what Browser::render() re-queries on every update, scoped
  // to the directory being browsed -- a TrashFile that did nothing would
  // leave this element in place indefinitely, so this is the assertion that
  // requires the feature to actually DO something (CLAUDE.md).
  await page.getByText(TRASH_CHECK_FILE_NAME, { exact: true }).waitFor({ state: 'detached', timeout: 10000 });
  console.log(`[${phase}] trashing ${TRASH_CHECK_FILE_NAME} through the detail panel removed it from the listing`);

  // SearchProjectionObserver::deleted() forgets the projection inline, not
  // queued (see the observer's own docblock), so the removal is visible to
  // the very next search -- no poll-until-gone needed here, unlike the
  // poll above that waited for the reindex job to land.
  await page.goto(`${BASE_URL}/search`, { waitUntil: 'domcontentloaded' });
  await page.getByLabel('Search', { exact: true }).fill(TRASH_CHECK_FILE_NAME);

  const stillFound = await page
    .getByText(TRASH_CHECK_FILE_NAME, { exact: true })
    .waitFor({ timeout: 5000 })
    .then(() => true)
    .catch(() => false);

  if (stillFound) {
    dumpContainerState(`[${phase}] ${TRASH_CHECK_FILE_NAME} is still findable by search after being trashed through the panel`);
    throw Object.assign(new Error(`${TRASH_CHECK_FILE_NAME} is still findable by search after being trashed`), { dumped: true });
  }

  console.log(`[${phase}] trashing through the panel also removed ${TRASH_CHECK_FILE_NAME} from search -- OK`);
}

/**
 * item/files-versions-replace (issue #102): drives the Replace control this
 * item wires into the file detail panel, against the real container, and
 * proves it adds a version rather than creating a second file or silently
 * overwriting the first one. tests/Feature/FileBrowserActionsTest.php
 * already covers the authorisation and the version bookkeeping through the
 * test renderer; what only a browser against the real image can see is
 * whether the wire:submit reaches replaceFile() at all, and whether the
 * page renders a second `input[type="file"]` in a way real Flux/Livewire JS
 * can actually drive (a broken asset build or an unresolved Flux component
 * leaves every Blade assertion green and this control unusable).
 *
 * Uses its OWN document, VERSIONS_CHECK_FILE_NAME, for the same reason
 * TRASH_CHECK_FILE_NAME exists: FILE_NAME/FILE_MARKER survive into the
 * 'verify' phase to prove persistence, and replacing that file's bytes here
 * would falsify that unrelated check.
 *
 * THE DIFFERING FILENAME IS THE PROOF. The replacement is deliberately saved
 * to disk and uploaded as 'DoccumSmokeReplacement.txt' -- a different name
 * from the document it replaces. If it instead shared the document's own
 * name, StoreFileVersion would version it anyway (that is what versioning
 * BY NAME means, see StoreFileVersionTest), and this check would pass
 * whether or not Browser::replaceFile() actually threads the selected file
 * through to the action correctly -- exactly the bug this item's mutation
 * (browser-replace-file's sibling in spirit, though that entry mutates the
 * authorize() call, not this line) is about: if replaceFile() ever went
 * through StoreFileVersion::handle() with $this->replacement's OWN client
 * name instead of StoreFileVersion::replace() with $this->selectedFile
 * itself (item/api-presigned-upload, issue #115 -- replace() is the entry
 * point that cannot take the create path at all, unlike handle(), which
 * decided "append" or "create" purely by which name string it was given),
 * the call would go through the CREATE path -- a second File row named
 * 'DoccumSmokeReplacement.txt' -- and only a differing name makes that
 * observable from the outside. The PROOF below is unchanged by which of
 * the two calls replaceFile() makes: it asserts the OUTCOME (the document's
 * own current version, version 1 untouched, no row under the replacement's
 * name), not which method got called, so it still fails identically against
 * an image reverted to the old handle()-with-client-name shape.
 *
 * The proof is a checksum triple read through tinker(), not DOM text:
 *
 *   1. the document's OWN currentVersion->checksum equals sha256(the
 *      REPLACEMENT body). This is the half that fails on the mutated image:
 *      there, the create path leaves VERSIONS_CHECK_FILE_NAME's current
 *      version pointing at the ORIGINAL body forever, because nothing ever
 *      touched that file's own row.
 *   2. version 1's checksum still equals sha256(the ORIGINAL body) --
 *      proves "adds a version, never overwrites", which is the issue's
 *      stated requirement, not merely "the current version changed".
 *   3. File::where('name', 'DoccumSmokeReplacement.txt')->doesntExist() --
 *      names the create-path mutation's symptom directly, rather than
 *      inferring it from the other two.
 *
 * max(version_number) is asserted at exactly 2. It was >= 2 while this check
 * retried the interaction, because a retry could legitimately land a third
 * version when an earlier attempt had silently succeeded after all; issue
 * #106 is fixed and the retry is gone, so a third version now means Replace
 * ran twice. The DOM count of
 * [data-test="file-version-row"] IS asserted at exactly 2, but subordinate
 * to the tinker checks above -- it is the only thing here proving the
 * version list actually renders in the shipped image (asset build, Flux
 * resolution), which is why CLAUDE.md wants a browser-driven check for a UI
 * item at all, but a mutation that broke replaceFile() itself while leaving
 * the (already-populated) version list rendering fine would sail through a
 * DOM-only assertion.
 *
 * MUTATION RECORD. Both directions were measured against a built image, not
 * reasoned about, because the first attempt at this check failed IDENTICALLY
 * in both and would have been recorded as proof:
 *
 *   correct build  -> image PASSES
 *     https://github.com/turbophp/doccum/actions/runs/35274858080/job/105382869492
 *
 *   Replace passing $this->replacement->getClientOriginalName() to
 *   StoreFileVersion instead of $this->selectedFile->name, i.e. the create
 *   path, on otherwise identical code (PR #118, head cb6d8bd)
 *                  -> image FAILS with
 *     "replace did not update the document's own current version checksum"
 *     https://github.com/turbophp/doccum/actions/runs/35274860882/job/105382879408
 *
 * The failing run reached the checksum triple and named the symptom, rather
 * than timing out on a selector -- which is the whole point of polling the
 * database before touching the DOM above. An earlier revision of this check
 * did time out on [data-test="file-version-row"].nth(1), in BOTH directions,
 * because it selected the uploaded row without re-navigating first; see the
 * comment on that goto.
 *
 * item/api-presigned-upload (issue #24) then gave StoreFileVersion a second
 * entry point, replace(File, ...), and moved Browser::replaceFile() onto it
 * (issue #115) -- $this->selectedFile->name is no longer an argument
 * replaceFile() passes at all, because replace() takes the File and derives
 * its own name internally, structurally unable to create a second row. The
 * PR #118 measurement above was against handle()-with-a-name and was not
 * re-run against a build reverted to that shape after this change, because
 * doing so needs a built image this environment cannot produce (CLAUDE.md:
 * no vendor/, no docker build here) -- flagged rather than claimed. What
 * carries the proof forward without a fresh image run is that this check's
 * three assertions name an OUTCOME (VERSIONS_CHECK_FILE_NAME's own current
 * checksum, version 1's untouched checksum, no row named
 * 'DoccumSmokeReplacement.txt'), not a call site, so the exact reverted
 * mutation PR #118 measured -- replaceFile() calling handle() with the
 * replacement's client name -- still produces the same observable symptom
 * (a second File row, VERSIONS_CHECK_FILE_NAME's own version never touched)
 * and still fails assertions 1 and 3 above for the identical reason it
 * failed them in that run.
 */
async function checkReplaceAddsASecondVersion(page, phase) {
  const originalBody = 'Version 1 body, unique to the replace smoke check.\n';
  const replacementBody = 'Version 2 body, deliberately different from version 1.\n';
  const replacementFileName = 'DoccumSmokeReplacement.txt';

  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="directories-list"]').getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.locator('[data-test="upload-form"] input[type="file"]').waitFor({ state: 'attached', timeout: 10000 });

  await uploadAndProveStored(page, VERSIONS_CHECK_FILE_NAME, originalBody, phase);

  // Re-navigate before selecting the row, exactly as
  // checkTrashRemovesFileFromListingAndSearch() does, and for a reason worth
  // stating because omitting it cost a whole mutation cycle here.
  //
  // Straight after an upload the main form's file input still displays the
  // name of the file just chosen, so the page carries that text TWICE: once
  // as the listing's select link and once as the input's own rendering of
  // its filename. getByText() then does not reliably land on the link, and
  // selectFile() never fires -- no detail panel, no version list, and the
  // wait below times out. It timed out identically on the correct build and
  // on the create-path mutation, which is precisely a check that proves
  // nothing: mutation-check.php asserts BOTH directions for the same
  // reason, and this check has to earn its keep the same way. A fresh
  // navigation clears the input and leaves exactly one match. Issue #107
  // recorded this same input-draws-the-filename behaviour fooling an
  // upload assertion; it fools a selection the same way.
  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="directories-list"]').getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.getByText(VERSIONS_CHECK_FILE_NAME, { exact: true }).waitFor({ timeout: 10000 });

  // Through the row, not the name: clicking a file's NAME now opens it in the
  // preview dialog, and this check wants the detail panel behind it rather
  // than a dialog over it. Selecting exactly one row populates that panel.
  await clickFileRow(page, VERSIONS_CHECK_FILE_NAME);

  // Two input[type="file"] elements exist on the page from this point on --
  // the main upload form's and this now-visible Replace form's -- so every
  // file-input locator in this file is scoped to the form that owns it. A
  // bare locator would throw Playwright's strict-mode "resolved to 2
  // elements" the moment a file is selected.
  //
  // The scope hangs off the <form>, which is plain HTML. Flux is only KNOWN
  // to forward arbitrary attributes on flux:button -- data-test=
  // "trash-file-button" is driven that way by a check that has passed CI and
  // been mutation-proven -- and there is no such precedent for flux:input,
  // which renders a label/wrapper around the real <input>. An attribute
  // landing on that wrapper is somewhere setInputFiles() cannot reach.
  // See the Blade template's comment on these two forms.
  const replacementPath = path.join(os.tmpdir(), replacementFileName);
  fs.writeFileSync(replacementPath, replacementBody);

  const expectedV2Checksum = crypto.createHash('sha256').update(replacementBody).digest('hex');
  const expectedV1Checksum = crypto.createHash('sha256').update(originalBody).digest('hex');

  const php = [
    `$f = \\App\\Models\\File::where('name', '${VERSIONS_CHECK_FILE_NAME}')->first();`,
    "if (!$f) { echo 'FILE:no'; } else {",
    '$f->refresh();',
    "echo 'CURRENT_CHECKSUM:' . $f->currentVersion->checksum;",
    "$v1 = $f->versions()->where('version_number', 1)->first();",
    "echo ' V1_CHECKSUM:' . ($v1 ? $v1->checksum : 'MISSING');",
    "echo ' MAX_VERSION:' . $f->versions()->max('version_number');",
    '}',
    `echo ' REPLACEMENT_NAME_EXISTS:' . (\\App\\Models\\File::where('name', '${replacementFileName}')->exists() ? 'yes' : 'no');`,
  ].join(' ');

  // Synchronised on the DATABASE, not on the DOM, and deliberately so.
  //
  // The first version of this check waited on [data-test="file-version-row"]
  // .nth(1) before reading anything, and called that wait "subordinate" to
  // the checksum triple below. The mutated image then failed on exactly that
  // wait -- a bare 10s Playwright timeout naming a selector -- and never
  // reached a single checksum. The assertion that fired was the one the
  // docblock called secondary, and the evidence the docblock called primary
  // went unproven. That is decision/0012's lesson repeating: which half is
  // load-bearing is not something to reason about, only to measure.
  //
  // So the wait is a poll on the rows themselves, and its failure path
  // carries all three pieces of evidence. On the create-path mutation this
  // reports "a File row named DoccumSmokeReplacement.txt exists" rather than
  // a selector timeout -- the symptom named, not merely detected.
  //
  // Polled, not retried, and that distinction moved with issue #106's cause.
  //
  // This check used to retry the whole interaction three times, because
  // Replace posts through the same Livewire upload path that silently
  // discarded uploads, and polling cannot rescue a click whose upload was
  // discarded: there is nothing in flight to wait for, so it spent the whole
  // window waiting for something that was never coming and then reported
  // "replace did not update the document's own current version checksum" --
  // which reads like a product failure and was not one. It went red exactly
  // that way on a LEDGER-ONLY pull request whose diff was two .jsonld files.
  //
  // PR #129 found the cause: the submit click raced the file input's own
  // upload POST, and store()/replaceFile() ran against a property Livewire
  // had not populated yet. browser.blade.php now disables both submit buttons
  // for the whole upload, so the racing click cannot be made, and
  // checkUploadButtonIsDisabledWhileTheFileIsStillUploading() asserts that
  // guard against a deliberately stalled upload endpoint.
  //
  // With the race closed, a retry here would only hide its return, so there
  // is one attempt. The POLL below stays: a replacement that lands still
  // takes a moment to become visible to the database, and that was always a
  // separate concern from the discarded click.
  const replaceInput = page.locator('[data-test="replace-form"] input[type="file"]');
  await replaceInput.waitFor({ state: 'attached', timeout: 10000 });
  await setFileAndWaitForUpload(page, replaceInput, replacementPath);

  // Through clickOnceUploadSettles(), for the same reason the main upload
  // form goes through it: Replace carries the same in-flight guard, so it
  // carries the same check-then-act race against it.
  await clickOnceUploadSettles(
    page,
    page.locator('[data-test="replace-file-button"]'),
    phase,
    'the Replace button',
  );

  const replaceDeadline = Date.now() + REPLACE_TIMEOUT_MS;
  let output = tinker(php);

  while (Date.now() < replaceDeadline && Number(/MAX_VERSION:(\d+)/.exec(output)?.[1] ?? '0') < 2) {
    await sleep(POLL_INTERVAL_MS);
    output = tinker(php);
  }

  if (Number(/MAX_VERSION:(\d+)/.exec(output)?.[1] ?? '0') >= 2) {
    console.log(`[${phase}] the replacement landed a second version`);
  }

  if (/FILE:no/.test(output)) {
    dumpContainerState(`[${phase}] ${VERSIONS_CHECK_FILE_NAME} disappeared before the replace checksum check could run -- raw output: ${output}`);
    throw Object.assign(new Error(`no files row for ${VERSIONS_CHECK_FILE_NAME} at the replace checksum check`), { dumped: true });
  }

  const currentChecksum = /CURRENT_CHECKSUM:(\S+)/.exec(output)?.[1];
  const v1Checksum = /V1_CHECKSUM:(\S+)/.exec(output)?.[1];
  const maxVersion = Number(/MAX_VERSION:(\d+)/.exec(output)?.[1] ?? '0');
  const replacementNameExists = /REPLACEMENT_NAME_EXISTS:(\S+)/.exec(output)?.[1] === 'yes';

  // 1. This is the half that fails on the mutated image: there, the
  // document's own current checksum never changes, because Replace took the
  // create path and wrote a SECOND file instead.
  if (currentChecksum !== expectedV2Checksum) {
    dumpContainerState(
      `[${phase}] ${VERSIONS_CHECK_FILE_NAME}'s current version checksum is "${currentChecksum}", expected sha256(replacement body) = "${expectedV2Checksum}" -- Replace did not update the document's own current version`,
    );
    throw Object.assign(new Error('replace did not update the document\'s own current version checksum'), { dumped: true });
  }
  console.log(`[${phase}] ${VERSIONS_CHECK_FILE_NAME}'s current version now hashes to the REPLACEMENT body -- Replace touched the right file`);

  // 2. Proves "adds a version, never overwrites" -- the issue's stated
  // requirement -- not merely "something changed".
  if (v1Checksum !== expectedV1Checksum) {
    dumpContainerState(
      `[${phase}] version 1 of ${VERSIONS_CHECK_FILE_NAME} now hashes to "${v1Checksum}", expected the untouched original sha256 = "${expectedV1Checksum}" -- version 1 was overwritten instead of a new version being added`,
    );
    throw Object.assign(new Error('version 1 was overwritten instead of a new version being added'), { dumped: true });
  }
  console.log(`[${phase}] version 1 of ${VERSIONS_CHECK_FILE_NAME} still hashes to the ORIGINAL body -- nothing was overwritten`);

  // 3. Names the create-path mutation's symptom directly.
  if (replacementNameExists) {
    dumpContainerState(`[${phase}] a File row named "${replacementFileName}" exists -- Replace created a second file instead of a version`);
    throw Object.assign(new Error(`a File row named "${replacementFileName}" exists`), { dumped: true });
  }
  console.log(`[${phase}] no File row is named "${replacementFileName}" -- Replace did not create a second file`);

  // Exactly 2, not ">= 2". The looser form was there because the retry above
  // could legitimately land a third version when an earlier attempt had
  // silently succeeded after all; with one attempt, a third version means
  // Replace ran twice and that is worth failing on.
  if (maxVersion !== 2) {
    dumpContainerState(`[${phase}] ${VERSIONS_CHECK_FILE_NAME}'s max version_number is ${maxVersion}, expected exactly 2`);
    throw Object.assign(new Error(`max version_number is ${maxVersion}, expected exactly 2`), { dumped: true });
  }
  console.log(`[${phase}] ${VERSIONS_CHECK_FILE_NAME} has max(version_number) = ${maxVersion} -- OK`);

  // Only now the DOM, and only as its own distinct claim: the database says
  // two versions exist, so the panel must actually render them. This is what
  // catches a version list that a broken asset build or an unresolved Flux
  // component leaves blank while every row sits in the database -- the one
  // failure a Blade assertion in the suite cannot see, and the reason
  // CLAUDE.md wants a browser here at all. It is no longer carrying the
  // create-path mutation; the checksum triple above does that.
  const versionRows = page.locator('[data-test="file-version-row"]');
  await versionRows.nth(1).waitFor({ state: 'visible', timeout: 10000 }).catch(() => {});
  const rowCount = await versionRows.count();

  if (rowCount < 2) {
    dumpContainerState(
      `[${phase}] the database reports max(version_number) = ${maxVersion} for ${VERSIONS_CHECK_FILE_NAME}, but the panel rendered ${rowCount} [data-test="file-version-row"] element(s) -- the version list is not reaching the browser in this image`,
    );
    throw Object.assign(new Error(`version list rendered ${rowCount} rows for ${maxVersion} versions`), { dumped: true });
  }
  console.log(`[${phase}] the version list rendered ${rowCount} rows -- the panel genuinely renders in this image`);
}

/**
 * Clicks a files-table row identified by its exact filename, through the
 * OWNER column's plain <td> rather than the name link. The name link ALSO
 * calls wire:click="selectFile(...)" (see the Blade template's comment on
 * this row) -- harmless, but unrelated to what any caller of this helper is
 * proving, and clicking a plain cell keeps every call here about exactly
 * one thing: the row's Alpine click handler, x-on:click="$wire.selectRow(...)".
 *
 * Optionally applies Shift/Control so the click Playwright dispatches
 * carries the modifier keys that handler reads
 * ($event.shiftKey, $event.ctrlKey || $event.metaKey) -- neither of which a
 * Livewire test-renderer ->call('selectRow', ...) can exercise, only an
 * actual browser click. This is the reason checkBulkTrashLeavesUnselectedFilesAlone()
 * below drives the UI at all rather than calling the component method
 * directly the way tests/Feature/FileBrowserListTest.php does.
 */
async function clickFileRow(page, name, { shift = false, ctrl = false } = {}) {
  const row = page.locator('tr[data-test="file-row"]').filter({ hasText: name });
  await row.waitFor({ state: 'visible', timeout: 10000 });

  const modifiers = [];
  if (shift) modifiers.push('Shift');
  if (ctrl) modifiers.push('Control');

  // The row's own checkbox, not a cell: a cell click lands wherever the
  // bounding box happens to centre, which can be the name link, and the
  // checkbox is the control a real operator uses to multi-select anyway.
  // It carries .stop, so exactly one selectRow() call leaves the browser.
  await row.locator('[data-test="file-row-checkbox"]').click({ modifiers });
}

/**
 * item/download-reaches-the-browser (issue #74): follows the Download link
 * the product actually renders and asserts the bytes arrive and match what
 * was uploaded.
 *
 * THIS CHECK WAS PUSHED BEFORE THE FIX EXISTED, AND FAILED. That is the
 * whole reason to trust it. Everything asserted about issue #74 until then
 * -- the original issue, the review that promoted it to a backlog item, and
 * a verification pass over the same files -- was a reading of the code, and
 * nobody had watched the download fail.
 *
 * RECORD, both directions, same assertion, unchanged between them:
 *
 *   before the fix (PR #127, head a22a1de) -> image FAILS
 *     [setup] following the Download link for DoccumSmokeDownloadTarget.txt:
 *             http://127.0.0.1:8080/files/4/download
 *     [setup] Download ... never produced a response: apiRequestContext.get:
 *             connect ECONNREFUSED 127.0.0.1:9000
 *     https://github.com/turbophp/doccum/actions/runs/35288804743/job/105426944343
 *
 *   with the fix (PR #127, head 683fa1d) -> image PASSES
 *
 * Note what the failing run showed that a reading could not have: the
 * Download link itself was fine -- port 8080, the published one -- and the
 * request reached the controller and was authorised. It is the REDIRECT
 * TARGET that refused the connection. That is the defect located, not merely
 * detected.
 *
 * What the reading says will happen: FileDownloadController redirects to a
 * presigned URL whose host comes from the documents disk's endpoint, which
 * for embedded storage defaults to http://127.0.0.1:9000 (config/doccum.php).
 * From the browser that is the browser's OWN machine, and the single
 * container publishes 8080 only -- so the redirect should lead nowhere.
 *
 * Deliberately NOT asserted: the specific failure. Whether Playwright sees a
 * connection refusal, a timeout, or a 403 from something else listening on
 * 9000 is not the point and pinning it would make this check a description
 * of one environment. What is asserted is the thing an operator cares about:
 * clicking Download produces the bytes that were uploaded.
 *
 * Uses page.request rather than a click so the assertion is about the HTTP
 * result rather than about browser download plumbing: it follows redirects,
 * carries the session cookies, and hands back the body to hash. A click that
 * opened a save dialog would prove less and be harder to read when it broke.
 */
async function checkDownloadReturnsTheUploadedBytes(page, phase) {
  const name = 'DoccumSmokeDownloadTarget.txt';
  const body = 'Downloaded bytes must match these exactly, byte for byte.\n';
  const expected = crypto.createHash('sha256').update(body).digest('hex');

  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="directories-list"]').getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.locator('[data-test="upload-form"] input[type="file"]').waitFor({ state: 'attached', timeout: 10000 });

  await uploadAndProveStored(page, name, body, phase);

  // Re-navigate before reading the row, for the reason recorded on
  // checkReplaceAddsASecondVersion(): straight after an upload the form's
  // file input still displays the chosen name, so the page carries it twice.
  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="directories-list"]').getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();

  await downloadAndCompareBytes(page, name, expected, phase);
}

/**
 * Follows a listed file's Download link and requires the response body to
 * hash to `expectedSha`. Split out of checkDownloadReturnsTheUploadedBytes()
 * so the VERIFY phase can reuse it; see runVerify() for why that matters.
 *
 * The caller is responsible for being on a listing that shows the row.
 */
async function downloadAndCompareBytes(page, name, expectedSha, phase) {
  const row = page.locator('tr[data-test="file-row"]').filter({ hasText: name });
  await row.waitFor({ state: 'visible', timeout: 10000 });

  const href = await row.getByRole('link', { name: 'Download', exact: true }).getAttribute('href');

  if (! href) {
    dumpContainerState(`[${phase}] ${name}'s row renders no Download link`);
    throw Object.assign(new Error(`no Download href for ${name}`), { dumped: true });
  }

  console.log(`[${phase}] following the Download link for ${name}: ${href}`);

  const response = await page.request
    .get(href, { maxRedirects: 5, timeout: 20000 })
    .catch((error) => ({ failed: String(error && error.message).split('\n')[0] }));

  if (response.failed !== undefined) {
    dumpContainerState(
      `[${phase}] Download for ${name} never produced a response: ${response.failed}` +
      ' -- the redirect target is unreachable from the browser (issue #74)',
    );
    throw Object.assign(new Error(`Download unreachable for ${name}: ${response.failed}`), { dumped: true });
  }

  if (! response.ok()) {
    dumpContainerState(
      `[${phase}] Download for ${name} answered HTTP ${response.status()}` +
      ' -- the file row exists but its BYTES did not come back',
    );
    throw Object.assign(new Error(`Download answered HTTP ${response.status()} for ${name}`), { dumped: true });
  }

  const got = crypto.createHash('sha256').update(await response.body()).digest('hex');

  if (got !== expectedSha) {
    dumpContainerState(
      `[${phase}] Download for ${name} answered ${response.status()} but the bytes hash to ${got},` +
      ` expected ${expectedSha} -- something answered that is not the document`,
    );
    throw Object.assign(new Error(`Download body mismatch for ${name}`), { dumped: true });
  }

  console.log(`[${phase}] Download returned the stored bytes for ${name} -- OK`);
}

/**
 * item/files-list-sort-select (issue #103): drives the files table's
 * multi-select and bulk-trash control against the real container. Uploads
 * three distinct files, selects exactly two of them -- a plain click then a
 * ctrl-click, via clickFileRow() above -- bulk-trashes the selection, and
 * proves the THIRD, unselected file survives.
 *
 * Modelled on checkReplaceAddsASecondVersion() above -- read that function's
 * docblock first, it encodes two lessons this reuses rather than re-learns:
 *
 *   1. Re-navigate before selecting an uploaded row. Straight after an
 *      upload the main form's file input still displays the just-chosen
 *      filename, so the page carries that text twice and a text-based
 *      locator does not reliably land on the right element -- it fooled an
 *      upload assertion (issue #107) and a replace-panel selection
 *      (issue #103's own note on checkReplaceAddsASecondVersion) the same
 *      way, so this re-navigates before clicking any uploaded row too.
 *   2. Database evidence first, DOM checks second. tinker() is polled for
 *      every file's trashed state before touching the page at all, so a
 *      broken build reports which file was (or was not) trashed by name,
 *      rather than timing out on a selector for a reason that looks
 *      nothing like the cause.
 *
 * THE SURVIVOR ASSERTION IS THE LOAD-BEARING ONE, not the two "was trashed"
 * assertions. The mutation this has to fail against is "bulkTrash ignores
 * the selection and trashes every file in the directory" -- and that
 * mutation trashes both selected files exactly as well as a correct build
 * does, so a check that only asked about the two SELECTED files would pass
 * identically on both. Only "the THIRD, unselected file is still live"
 * tells them apart -- the same reasoning CLAUDE.md's note on the topbar
 * check makes: which half of a check is load-bearing is not something to
 * argue about, only to measure.
 *
 * MUTATION RECORD, both directions measured against a built image:
 *
 *   correct build (#120, head c1b4bd2) -> image PASSES
 *     https://github.com/turbophp/doccum/actions/runs/35279512648/job/105397955034
 *
 *   bulkTrash() taking every file in the directory instead of the selected
 *   ones, with the exact-resolution guard dropped so it does not abort
 *   first (#122, head 5632724) -> image FAILS with
 *     "DoccumSmokeBulkSurvivor.txt is not live after a bulk trash that
 *      should not have selected it"
 *     raw: SURVIVOR_LIVE:no V1_TRASHED:yes V2_TRASHED:yes
 *     https://github.com/turbophp/doccum/actions/runs/35279957311/job/105399358226
 *
 * Note what the PASSING direction proves that the failing one cannot. Under
 * the mutation the selection is ignored entirely, so that run says nothing
 * about whether multi-select works. The green run does: the survivor stayed
 * live while exactly the two clicked rows were trashed, which can only
 * happen if the checkbox clicks actually reached selectRow() and left
 * $selectedIds holding those two ids. That is the only evidence anywhere
 * that the Alpine mechanism -- $wire.selectRow() reading $event.shiftKey
 * and $event.ctrlKey -- functions in the shipped image at all; no Blade
 * assertion in the suite can see it.
 *
 * Both runs are recorded because one alone is not a proof. The replace
 * check one item earlier failed IDENTICALLY in both directions on its first
 * attempt and was minutes from being written down as evidence. A red image
 * job also has to be read for WHERE it died, not merely that it did: issue
 * #121 has the container intermittently dying at extraction with a locked
 * jobs table, which happened on this very PR's first run.
 */
async function checkBulkTrashLeavesUnselectedFilesAlone(page, phase) {
  const survivorName = 'DoccumSmokeBulkSurvivor.txt';
  const victimOneName = 'DoccumSmokeBulkVictimOne.txt';
  const victimTwoName = 'DoccumSmokeBulkVictimTwo.txt';

  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="directories-list"]').getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.locator('[data-test="upload-form"] input[type="file"]').waitFor({ state: 'attached', timeout: 10000 });

  await uploadAndProveStored(page, survivorName, 'Must still be listed after the bulk trash below.\n', phase);
  await uploadAndProveStored(page, victimOneName, 'Selected for bulk trash -- must end up trashed.\n', phase);
  await uploadAndProveStored(page, victimTwoName, 'Also selected for bulk trash -- must end up trashed.\n', phase);

  // Re-navigate before selecting anything, exactly as
  // checkReplaceAddsASecondVersion() does above and for the identical
  // reason: the upload form's file input still shows victimTwoName's
  // filename until the page is reloaded, so the listing carries that text
  // twice and clickFileRow()'s hasText filter does not reliably land on
  // the table row alone.
  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="directories-list"]').getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.locator('tr[data-test="file-row"]').filter({ hasText: survivorName }).waitFor({ timeout: 10000 });

  // Select victimOne and victimTwo, NOT survivor. Both clicks land on the
  // row's checkbox, so both are TOGGLES -- selectRow(id, shift, ctrl) is
  // called with ctrl true unless shift is held, because ticking a box means
  // "add this one", not "replace the selection with this one". The second
  // click additionally holds Control, which is the gesture a user reaches
  // for on the row itself; the box makes it redundant rather than wrong,
  // and exercising it here keeps the modifier path covered.
  //
  // What matters for this check either way is the state it leaves: exactly
  // two of the three rows selected, and the survivor untouched.
  await clickFileRow(page, victimOneName);
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  await clickFileRow(page, victimTwoName, { ctrl: true });
  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

  const bulkTrashButton = page.locator('[data-test="bulk-trash-button"]');
  await bulkTrashButton.waitFor({ state: 'visible', timeout: 10000 });

  page.once('dialog', (dialog) => dialog.accept());
  await bulkTrashButton.click();

  const php = [
    `$survivor = \\App\\Models\\File::where('name', '${survivorName}')->first();`,
    `$v1 = \\App\\Models\\File::withTrashed()->where('name', '${victimOneName}')->first();`,
    `$v2 = \\App\\Models\\File::withTrashed()->where('name', '${victimTwoName}')->first();`,
    "echo 'SURVIVOR_LIVE:' . ($survivor && !$survivor->trashed() ? 'yes' : 'no');",
    "echo ' V1_TRASHED:' . ($v1 && $v1->trashed() ? 'yes' : 'no');",
    "echo ' V2_TRASHED:' . ($v2 && $v2->trashed() ? 'yes' : 'no');",
  ].join(' ');

  // Synchronised on the DATABASE, not on the DOM -- see the docblock above.
  const deadline = Date.now() + REPLACE_TIMEOUT_MS;
  let output = tinker(php);

  while (Date.now() < deadline && !/V1_TRASHED:yes/.test(output)) {
    await sleep(POLL_INTERVAL_MS);
    output = tinker(php);
  }

  const survivorLive = /SURVIVOR_LIVE:(\S+)/.exec(output)?.[1] === 'yes';
  const v1Trashed = /V1_TRASHED:(\S+)/.exec(output)?.[1] === 'yes';
  const v2Trashed = /V2_TRASHED:(\S+)/.exec(output)?.[1] === 'yes';

  // THE LOAD-BEARING ASSERTION. See the docblock above: "trash everything in
  // the directory, ignore the selection" leaves both victims trashed too --
  // only the survivor tells that mutation apart from a correct build.
  if (!survivorLive) {
    dumpContainerState(`[${phase}] ${survivorName} was NOT left live by bulkTrash() -- raw output: ${output}`);
    throw Object.assign(new Error(`${survivorName} is not live after a bulk trash that should not have selected it`), { dumped: true });
  }
  console.log(`[${phase}] ${survivorName} is still live -- bulk trash did not touch an unselected file`);

  if (!v1Trashed || !v2Trashed) {
    dumpContainerState(`[${phase}] bulk trash did not trash both selected files -- raw output: ${output}`);
    throw Object.assign(new Error('bulk trash did not trash both selected files'), { dumped: true });
  }
  console.log(`[${phase}] both selected files (${victimOneName}, ${victimTwoName}) were trashed -- OK`);

  // Only now the DOM: the listing Browser::render() re-queries on every
  // update must show the survivor and must NOT show either trashed file.
  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="directories-list"]').getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.getByText(survivorName, { exact: true }).waitFor({ timeout: 10000 });
  await page.getByText(victimOneName, { exact: true }).waitFor({ state: 'detached', timeout: 10000 });
  await page.getByText(victimTwoName, { exact: true }).waitFor({ state: 'detached', timeout: 10000 });
  console.log(`[${phase}] the listing shows only the survivor -- OK`);
}

/**
 * item/directory-access-ui (issue #14): granting and revoking a directory
 * grant through its detail panel's Access section.
 *
 * The grantee is a throwaway account created via tinker, the same pattern
 * checkResetPasswordCommandPrintsAWorkingLink() above uses and for the same
 * reason: User::factory() needs fakerphp/faker, a require-dev dependency
 * not autoloadable in this --no-dev image.
 *
 * Every assertion is checked against the DATABASE via tinker, not only the
 * DOM -- decision/0012, the same reasoning fileRowExists() above states:
 * the DOM can show an optimistic re-render of something that never reached
 * storage. The DOM waits below exist only to know WHEN to check the
 * database, never as the proof itself.
 *
 * THE LOAD-BEARING ASSERTION (predicted, not proven here -- the mutation is
 * run separately; see the task report): the DirectoryGrant row count
 * reading exactly 1 right after Grant and exactly 0 right after Revoke. A
 * broken manageAccess() authorisation would 403 before either write; a
 * flux:select whose value Flux failed to forward would submit an empty or
 * wrong grantLevel and 422 instead of writing; a dynamic wire:click
 * argument the built image dropped on the Revoke button would submit no
 * id at all. Every one of those leaves the grant COUNT wrong, which is
 * exactly what this checks, rather than an opaque locator timeout.
 */
async function checkGrantAndRevokeDirectoryAccess(page, phase) {
  const digits = Date.now().toString().slice(-9);
  const dirName = `DoccumSmokeAccessDir-${digits}`;
  const granteeEmail = `smoke-grantee-${digits}@example.test`;
  const granteeUsername = `smokegrantee${digits}`;

  const createPhp = [
    "$u = \\App\\Models\\User::create(['name' => 'Smoke Grantee',",
    `'username' => '${granteeUsername}', 'email' => '${granteeEmail}',`,
    "'password' => 'whatever-it-was-before']);",
    "$u->assignRole('member');",
    "echo 'CREATED:' . $u->email;",
  ].join(' ');

  const createOutput = tinker(createPhp);
  if (!createOutput.includes(`CREATED:${granteeEmail}`)) {
    dumpContainerState(`[${phase}] could not create the scratch grantee account for the access panel check -- raw output: ${createOutput}`);
    throw Object.assign(new Error('scratch grantee account creation for the access panel check failed'), { dumped: true });
  }

  function grantCount() {
    const php = [
      `$d = \\App\\Models\\Directory::where('name', '${dirName}')->first();`,
      `$g = \\App\\Models\\User::where('email', '${granteeEmail}')->first();`,
      "echo 'COUNT:' . (($d && $g) ? \\App\\Models\\DirectoryGrant::where('directory_id', $d->id)->where('grantee_type', 'user')->where('grantee_id', $g->id)->where('level', 'view')->count() : 'NA');",
    ].join(' ');
    return tinker(php);
  }

  // A fresh directory, never reused: an earlier check may have left other
  // directories with "Details" links in this same listing (issue #99's
  // reach-root landing pane), and this is what lets the row-scoped
  // locators below name THIS row unambiguously.
  const list = page.locator('[data-test="directories-list"]');
  await openNewFolderDialog(page);
  await page.getByLabel('Folder name', { exact: true }).fill(dirName);
  await Promise.all([
    list.getByText(dirName, { exact: true }).waitFor({ timeout: 10000 }),
    page.getByRole('button', { name: 'Create', exact: true }).click(),
  ]);

  // The Access section only renders for a SELECTED directory
  // (Browser::render()'s $selectedDirectory, not the one merely being
  // browsed) -- "Details" is the same control selectDirectory() above
  // opens the property panel through.
  const row = page.locator('[data-test="directories-list"] > tr').filter({ hasText: dirName });

  // getByLabel, NOT getByRole('link'). The row renders two flux:links and only
  // the first is a link in the accessibility tree: the directory name carries
  // :href, while Details carries wire:click alone, and an <a> with no href has
  // no link role. Details is also icon-only now, so there is no visible text
  // to match either -- its aria-label is the name. getByRole('link', { name: 'Details' }) therefore matches
  // nothing and waits out its full timeout:
  //
  //   locator.click: Timeout 30000ms exceeded.
  //     waiting for locator('[data-test="directories-list"] > tr')
  //       .filter({ hasText: '...' }).getByRole('link', { name: 'Details' })
  //
  // The name link one line above IS role=link, which is exactly what makes
  // this easy to get wrong -- the two look identical in the template.
  await row.getByLabel('Details', { exact: true }).click();
  await page.locator('[data-test="grant-access-form"]').waitFor({ state: 'visible', timeout: 10000 });

  // Deliberately leaves the Level <flux:select> at its default ('view',
  // Browser::$grantLevel's own initial value) rather than driving it with
  // Playwright's selectOption(): nothing else in this whole script
  // exercises a flux:select through a real browser (moveFileDestinationId/
  // moveDirectoryDestinationId have no smoke check of their own either), so
  // there is no precedent here for how Flux renders one under the hood --
  // possibly not a native <select> at all -- and this check's job is
  // grant/revoke, not settling that question too. grantCount() below
  // filters on level = 'view' accordingly.
  await page.getByLabel('Grant access to (email)', { exact: true }).fill(granteeEmail);

  const grantRow = page.locator('[data-test="directory-grant-row"]').filter({ hasText: granteeEmail });
  await Promise.all([
    grantRow.waitFor({ state: 'visible', timeout: 10000 }),
    page.locator('[data-test="grant-access-form"]').getByRole('button', { name: 'Grant', exact: true }).click(),
  ]);

  let output = grantCount();
  const grantDeadline = Date.now() + REPLACE_TIMEOUT_MS;
  while (Date.now() < grantDeadline && !/COUNT:1/.test(output)) {
    await sleep(POLL_INTERVAL_MS);
    output = grantCount();
  }

  if (!/COUNT:1/.test(output)) {
    dumpContainerState(`[${phase}] granting access through the panel did not write a DirectoryGrant row -- raw output: ${output}`);
    throw Object.assign(new Error('grantAccess() did not write a DirectoryGrant row'), { dumped: true });
  }
  console.log(`[${phase}] granting access through the panel wrote exactly one DirectoryGrant row -- OK`);

  // Click, then ask the DATABASE, and only then look at the DOM.
  //
  // This used to Promise.all the click with a wait for the row to become
  // detached -- which is the very thing revoking does, so the wait duplicated
  // the assertion AND came first. On a broken revoke it threw
  // "locator.waitFor: Timeout 10000ms exceeded ... 25 x locator resolved to
  // visible <li data-test="directory-grant-row">" before the named check
  // below could say "the grant row is still there". decision/0033's rule,
  // written one item ago: never wait on the thing under test, or a defect in
  // it surfaces as an opaque timeout instead of at the assertion.
  //
  // Database evidence first, DOM second, matching
  // checkBulkTrashLeavesUnselectedFilesAlone()'s docblock. The DOM check
  // still runs -- it is what proves the panel re-rendered rather than merely
  // that the row went -- but it is no longer what fails first.
  page.once('dialog', (dialog) => dialog.accept());
  await grantRow.getByRole('button', { name: 'Revoke', exact: true }).click();

  output = grantCount();
  const revokeDeadline = Date.now() + REPLACE_TIMEOUT_MS;
  while (Date.now() < revokeDeadline && !/COUNT:0/.test(output)) {
    await sleep(POLL_INTERVAL_MS);
    output = grantCount();
  }

  if (!/COUNT:0/.test(output)) {
    dumpContainerState(`[${phase}] revoking access through the panel did not remove the DirectoryGrant row -- raw output: ${output}`);
    throw Object.assign(new Error('revokeAccess() did not remove the DirectoryGrant row'), { dumped: true });
  }
  console.log(`[${phase}] revoking access through the panel removed the DirectoryGrant row -- OK`);

  await grantRow.waitFor({ state: 'detached', timeout: 10000 });
  console.log(`[${phase}] the panel stopped listing the revoked grant -- OK`);
}

/**
 * item/trash-view (issue #15): drives the new /trash page's own Restore and
 * Purge controls against the real image. tests/Feature/TrashViewTest.php
 * already covers authorisation and the query-level isolation through the
 * test renderer; what only a browser against the built container can see is
 * whether wire:click on this page's flux:button controls actually reaches
 * Index::restoreFile()/purgeFile() at all -- a broken asset build or an
 * unresolved Flux component leaves every Blade assertion green and both
 * controls unusable.
 *
 * The two targets are trashed through `tinker`, not the Browser's own Trash
 * button -- that flow is checkTrashRemovesFileFromListingAndSearch()'s job
 * above, already proven. Staying out of that path keeps this check's DOM
 * interaction scoped to the page actually under test.
 *
 * THE LOAD-BEARING ASSERTIONS (predicted, not proven here -- the mutation is
 * run separately; see the task report): RESTORE_LIVE:yes and PURGED_GONE:yes,
 * read from the database through tinker AFTER the two clicks, BEFORE either
 * is treated as done -- decision/0033's rule ("never wait on the thing under
 * test"), the same shape checkBulkTrashLeavesUnselectedFilesAlone() and
 * checkGrantAndRevokeDirectoryAccess() already use. A missing
 * `$this->authorize(...)` call would not be visible here (both callers are
 * the admin, who passes either way) -- that gap is FilePolicy/
 * DirectoryPolicy's own tests' job -- but a wire:click the built image
 * dropped, an unresolved flux:button, or Index::restoreFile()/purgeFile()
 * never reaching RestoreFile::handle()/PurgeFile::handle() all leave
 * RESTORE_LIVE:no or PURGED_GONE:no, which is what this actually proves.
 */
async function checkTrashViewRestoreAndPurge(page, phase) {
  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="directories-list"]').getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.locator('[data-test="upload-form"] input[type="file"]').waitFor({ state: 'attached', timeout: 10000 });

  await uploadAndProveStored(page, TRASH_VIEW_RESTORE_FILE_NAME, 'Trashed by tinker, restored through /trash.\n', phase);
  await uploadAndProveStored(page, TRASH_VIEW_PURGE_FILE_NAME, 'Trashed by tinker, purged through /trash.\n', phase);

  tinker(
    [
      `\\App\\Models\\File::where('name', '${TRASH_VIEW_RESTORE_FILE_NAME}')->first()->delete();`,
      `\\App\\Models\\File::where('name', '${TRASH_VIEW_PURGE_FILE_NAME}')->first()->delete();`,
      "echo 'TRASHED:done';",
    ].join(' '),
  );

  await page.goto(`${BASE_URL}/trash`, { waitUntil: 'domcontentloaded' });
  // Only ever true AT THIS DESTINATION: '/files' renders no element with
  // this data-test at all, unlike a heading or nav link that exists on both
  // pages and would prove nothing about which page actually loaded.
  await page.locator('[data-test="trashed-files-table"]').waitFor({ state: 'visible', timeout: 10000 });

  const restoreRow = page.locator('tr[data-test="trashed-file-row"]').filter({ hasText: TRASH_VIEW_RESTORE_FILE_NAME });
  await restoreRow.waitFor({ timeout: 10000 });
  // Settles the request the click itself starts, registered before the
  // click -- see clickAndWaitForLivewire(). Never a wait on the row itself,
  // which is the thing under test.
  await clickAndWaitForLivewire(page, restoreRow.locator('[data-test="restore-file-button"]'));

  const purgeRow = page.locator('tr[data-test="trashed-file-row"]').filter({ hasText: TRASH_VIEW_PURGE_FILE_NAME });
  await purgeRow.waitFor({ timeout: 10000 });
  page.once('dialog', (dialog) => dialog.accept());
  await clickAndWaitForLivewire(page, purgeRow.locator('[data-test="purge-file-button"]'));

  const php = [
    `$restored = \\App\\Models\\File::where('name', '${TRASH_VIEW_RESTORE_FILE_NAME}')->first();`,
    `$purged = \\App\\Models\\File::withTrashed()->where('name', '${TRASH_VIEW_PURGE_FILE_NAME}')->first();`,
    "echo 'RESTORE_LIVE:' . ($restored && !$restored->trashed() ? 'yes' : 'no');",
    "echo ' PURGED_GONE:' . ($purged === null ? 'yes' : 'no');",
  ].join(' ');

  // Database evidence first -- no polling loop here, deliberately: both
  // writes already happened synchronously inside the request each click
  // above waited out via clickAndWaitForLivewire(), unlike the queued work
  // checkBulkTrashLeavesUnselectedFilesAlone() polls for. This USED to say
  // "waited out via networkidle" -- that was exactly the bug (issue #182).
  // A bare networkidle registered after the click can resolve before
  // Livewire has even issued its XHR, since the request is not dispatched
  // synchronously inside the click handler. On the image job of run
  // 35328394591 that is what happened: RESTORE_LIVE:yes PURGED_GONE:no, with
  // no 4xx or 5xx anywhere in the container access log, because the purge
  // request was still in flight -- networkidle had already resolved -- when
  // the tinker read below reached the database first. clickAndWaitForLivewire()
  // now arms the response listener before each click, so the write really
  // has happened by the time this comment's promise is kept.
  const output = tinker(php);
  const restoreLive = /RESTORE_LIVE:(\S+)/.exec(output)?.[1] === 'yes';
  const purgedGone = /PURGED_GONE:(\S+)/.exec(output)?.[1] === 'yes';

  if (!restoreLive) {
    dumpContainerState(`[${phase}] ${TRASH_VIEW_RESTORE_FILE_NAME} was not restored through /trash -- raw output: ${output}`);
    throw Object.assign(new Error(`${TRASH_VIEW_RESTORE_FILE_NAME} is not live after Restore on /trash`), { dumped: true });
  }
  console.log(`[${phase}] ${TRASH_VIEW_RESTORE_FILE_NAME} is live again -- Restore on /trash works`);

  if (!purgedGone) {
    dumpContainerState(`[${phase}] ${TRASH_VIEW_PURGE_FILE_NAME} still exists after Purge on /trash -- raw output: ${output}`);
    throw Object.assign(new Error(`${TRASH_VIEW_PURGE_FILE_NAME} still exists after Purge on /trash`), { dumped: true });
  }
  console.log(`[${phase}] ${TRASH_VIEW_PURGE_FILE_NAME} is gone for good -- Purge on /trash works`);

  // Only now the DOM, and only as confirmation that the ordinary listing
  // agrees with what the database already proved above.
  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="directories-list"]').getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.getByText(TRASH_VIEW_RESTORE_FILE_NAME, { exact: true }).waitFor({ timeout: 10000 });
  console.log(`[${phase}] the restored file is back in the ordinary listing -- OK`);
}

/**
 * item/admin-users (issue #18): drives the new /admin/users page against
 * the real image. tests/Feature/AdminUsersTest.php, tests/Feature/
 * SetUserRolesTest.php and tests/Feature/LastAdministratorGuardTest.php
 * already prove all of this against the test renderer -- what only a real
 * browser against the built image can see is whether the create form's
 * wire:submit and the role control's wire:click actually reach
 * Users::save()/changeRole() at all, the same class of gap every other
 * admin surface in this file exists to catch (a broken asset build or an
 * unresolved Flux component leaves every Blade assertion green and the
 * control unusable).
 *
 * Covers three of the item's doneWhen clauses in one pass, in this order:
 *   1. a user created through the real form gets a home directory AND a
 *      manage grant on it (proven through tinker, polled, not read once --
 *      CreateUser's home-directory call is asynchronous with respect to
 *      nothing here, but this still follows the same polling shape as
 *      checkGrantAndRevokeDirectoryAccess() rather than assuming the
 *      request that answered the click already means the write landed);
 *   2. that new, roleless account is refused /admin/users with a real 403,
 *      not merely hidden from the nav;
 *   3. the sole admin cannot use the SAME page to strip their own
 *      users.manage, and sees why instead of a 500.
 *
 * The new user is deliberately left with NO role through the create form
 * (the role <flux:select> is never touched) -- not an oversight, but what
 * makes ADMIN_USERNAME provably the SOLE holder of users.manage going into
 * step 3 below, without a second tinker call to strip a role the form
 * might otherwise have granted.
 */
async function checkAdminUsersPage(page, phase) {
  const digits = Date.now().toString().slice(-9);
  const memberUsername = `smokemember${digits}`;
  const memberEmail = `smoke-member-${digits}@example.test`;
  const memberPassword = `Doccum-Smoke-Member-${digits}!Aa`;

  console.log(`[${phase}] opening /admin/users as the administrator`);
  await page.goto(`${BASE_URL}/admin/users`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="create-user-form"]').waitFor({ state: 'visible', timeout: 10000 });

  await page.getByLabel('Name', { exact: true }).fill('Smoke Member');
  await page.getByLabel('Username', { exact: true }).fill(memberUsername);
  await page.getByLabel('Email address', { exact: true }).fill(memberEmail);
  await page.getByLabel('Password', { exact: true }).fill(memberPassword);
  await page.getByLabel('Confirm password', { exact: true }).fill(memberPassword);

  const memberRow = page.locator('[data-test="user-row"]').filter({ hasText: memberUsername });
  await Promise.all([
    memberRow.waitFor({ timeout: 10000 }),
    page.locator('[data-test="create-user-form"]').getByRole('button', { name: 'Create user', exact: true }).click(),
  ]);
  console.log(`[${phase}] the admin users page lists ${memberUsername}, just created through the real form -- OK`);

  function homeAndGrant() {
    const php = [
      `$u = \\App\\Models\\User::where('username', '${memberUsername}')->first();`,
      "$d = $u ? \\App\\Models\\Directory::where('home_user_id', $u->id)->first() : null;",
      "$g = ($u && $d) ? \\App\\Models\\DirectoryGrant::where('directory_id', $d->id)"
        + "->where('grantee_type', 'user')->where('grantee_id', $u->id)->where('level', 'manage')->exists() : false;",
      "echo 'HOME:' . ($d ? 'yes' : 'no') . ' GRANT:' . ($g ? 'yes' : 'no');",
    ].join(' ');
    return tinker(php);
  }

  let output = homeAndGrant();
  const homeDeadline = Date.now() + REPLACE_TIMEOUT_MS;
  while (Date.now() < homeDeadline && !(/HOME:yes/.test(output) && /GRANT:yes/.test(output))) {
    await sleep(POLL_INTERVAL_MS);
    output = homeAndGrant();
  }

  if (!/HOME:yes/.test(output)) {
    dumpContainerState(
      `[${phase}] ${memberUsername}, created through /admin/users, got no home directory -- raw output: ${output}`,
    );
    throw Object.assign(new Error(`${memberUsername} has no home directory after admin creation`), { dumped: true });
  }
  if (!/GRANT:yes/.test(output)) {
    dumpContainerState(
      `[${phase}] ${memberUsername} has a home directory but no manage grant on it -- raw output: ${output}`,
    );
    throw Object.assign(new Error(`${memberUsername} has no manage grant on their own home directory`), { dumped: true });
  }
  console.log(`[${phase}] ${memberUsername} got a home directory and a manage grant on it, through the admin page -- OK`);

  console.log(`[${phase}] logging in as ${memberUsername} and checking /admin/users answers 403 for them`);
  const memberContext = await page.context().browser().newContext();
  try {
    const memberPage = await memberContext.newPage();
    await memberPage.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
    await memberPage.getByLabel('Username or email', { exact: true }).fill(memberUsername);
    await memberPage.getByLabel('Password', { exact: true }).fill(memberPassword);
    await Promise.all([
      memberPage.waitForURL((u) => u.pathname !== '/login', { timeout: 15000 }),
      memberPage.getByRole('button', { name: 'Log in' }).click(),
    ]);

    // mutation/0019: goto() follows redirects, so `status` is the FINAL
    // response, not necessarily /admin/users's own. A 403 straight from
    // /admin/users and a 200 from a bounce to the verification notice are
    // both "the status is not 403 for the page this was asked for" in two
    // different ways, so describeLanding() (above fileRowExists()) is read
    // alongside the status rather than the status standing alone -- a bare
    // "HTTP 200, expected 403" is exactly what that mutation's run produced,
    // and it reads like an authorisation bypass whether or not it is one.
    const response = await memberPage.goto(`${BASE_URL}/admin/users`, { waitUntil: 'domcontentloaded' });
    const status = response ? response.status() : null;
    const landing = describeLanding(memberPage);

    if (status !== 403) {
      dumpContainerState(
        `[${phase}] /admin/users answered HTTP ${status} for ${memberUsername}, who holds no users.manage -- expected 403. ${landing}.`,
      );
      throw Object.assign(
        new Error(`/admin/users did not 403 for ${memberUsername} (answered ${status}) -- ${landing}`),
        { dumped: true },
      );
    }
    console.log(`[${phase}] /admin/users answered 403 for ${memberUsername}, ${landing} -- OK`);
  } finally {
    await memberContext.close();
  }

  console.log(`[${phase}] attempting, through the UI, to change the sole admin's own role away from admin`);
  const adminRow = page.locator('[data-test="user-row"]').filter({ hasText: ADMIN_USERNAME });

  try {
    await adminRow.locator('[data-test="role-select"]').selectOption('member');
  } catch (error) {
    dumpContainerState(
      `[${phase}] could not select 'member' on the role <flux:select> for ${ADMIN_USERNAME}'s row -- ${error.message}`,
    );
    throw Object.assign(new Error(`role <flux:select> did not accept selectOption('member') for ${ADMIN_USERNAME}`), { dumped: true });
  }

  const lastAdminError = page.locator('[data-test="last-admin-error"]');
  if (await lastAdminError.isVisible()) {
    dumpContainerState(`[${phase}] the last-admin refusal was already visible before Update role was clicked for ${ADMIN_USERNAME}`);
    throw Object.assign(new Error('last-admin-error was visible before the refusing click, so it proves nothing about the click'), { dumped: true });
  }

  await adminRow.locator('[data-test="change-role-button"]').click();

  function adminStillHoldsUsersManage() {
    const php = [
      `$u = \\App\\Models\\User::where('username', '${ADMIN_USERNAME}')->first();`,
      "echo 'HOLDS:' . ($u && $u->can('users.manage') ? 'yes' : 'no');",
    ].join(' ');
    return tinker(php);
  }

  let holdsOutput = adminStillHoldsUsersManage();
  const holdsDeadline = Date.now() + REPLACE_TIMEOUT_MS;
  while (Date.now() < holdsDeadline && !/HOLDS:yes/.test(holdsOutput)) {
    await sleep(POLL_INTERVAL_MS);
    holdsOutput = adminStillHoldsUsersManage();
  }

  if (!/HOLDS:yes/.test(holdsOutput)) {
    dumpContainerState(
      `[${phase}] ${ADMIN_USERNAME} no longer holds users.manage after the refused role change -- raw output: ${holdsOutput}`,
    );
    throw Object.assign(new Error(`${ADMIN_USERNAME} lost users.manage through a change the guard was supposed to refuse`), { dumped: true });
  }
  console.log(`[${phase}] ${ADMIN_USERNAME} still holds users.manage after the attempted change -- the write was refused -- OK`);

  try {
    await lastAdminError.waitFor({ state: 'visible', timeout: 10000 });
  } catch {
    dumpContainerState(
      `[${phase}] the database confirms the last-admin change was refused, but [data-test="last-admin-error"] never became visible`
      + ' -- the guard held, but the operator would have seen nothing explaining why the click did nothing',
    );
    throw Object.assign(new Error('last-admin-error never became visible after a refused role change'), { dumped: true });
  }
  console.log(`[${phase}] the last-administrator refusal is visible on the page -- OK`);
}

/**
 * item/admin-roles (issue #19): drives the roles x permissions matrix at
 * /admin/roles the way an operator would -- toggle a box, save, prove the
 * database moved; then attempt the refused change and prove it did NOT.
 * Follows checkAdminUsersPage() above exactly (same "prove the effect, not
 * a resting state" shape CLAUDE.md asks for): every failure path dumps
 * container state naming what was being proved, then throws with
 * { dumped: true }, and the last-admin error element is checked absent
 * BEFORE the refusing click so its later visibility actually proves
 * something about that click, not merely that the element can render.
 */
async function checkAdminRolesPage(page, phase) {
  console.log(`[${phase}] opening /admin/roles as the administrator`);
  await page.goto(`${BASE_URL}/admin/roles`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="roles-permissions-table"]').waitFor({ state: 'visible', timeout: 10000 });

  const memberRow = page.locator('[data-test="role-row"][data-role-name="member"]');
  const memberPeriodsCheckbox = memberRow.locator('[data-test="role-permission-checkbox"][data-permission="periods.manage"]');

  if (await memberPeriodsCheckbox.isChecked()) {
    dumpContainerState(
      `[${phase}] the member role's periods.manage checkbox was already checked before checkAdminRolesPage toggled it`
      + ' -- RolesAndPermissionsSeeder::MEMBER_PERMISSIONS changed underneath this check',
    );
    throw Object.assign(new Error('member role already holds periods.manage before the toggle'), { dumped: true });
  }

  console.log(`[${phase}] granting periods.manage to the member role through the real form`);
  await memberPeriodsCheckbox.check();
  await memberRow.locator('[data-test="save-role-permissions-button"]').click();

  function memberHoldsPeriodsManage() {
    const php = [
      "$r = \\Spatie\\Permission\\Models\\Role::findByName('member');",
      "echo 'HOLDS:' . ($r->hasPermissionTo('periods.manage') ? 'yes' : 'no');",
    ].join(' ');
    return tinker(php);
  }

  let output = memberHoldsPeriodsManage();
  const grantDeadline = Date.now() + REPLACE_TIMEOUT_MS;
  while (Date.now() < grantDeadline && !/HOLDS:yes/.test(output)) {
    await sleep(POLL_INTERVAL_MS);
    output = memberHoldsPeriodsManage();
  }

  if (!/HOLDS:yes/.test(output)) {
    dumpContainerState(
      `[${phase}] toggling periods.manage for the member role through /admin/roles never reached the database -- raw output: ${output}`,
    );
    throw Object.assign(new Error('member role never gained periods.manage through the real form'), { dumped: true });
  }
  console.log(`[${phase}] the member role gained periods.manage through the real form -- OK`);

  console.log(`[${phase}] attempting, through the UI, to uncheck users.manage on the admin role`);
  const adminRow = page.locator('[data-test="role-row"][data-role-name="admin"]');
  const adminUsersManageCheckbox = adminRow.locator('[data-test="role-permission-checkbox"][data-permission="users.manage"]');

  if (!(await adminUsersManageCheckbox.isChecked())) {
    dumpContainerState(`[${phase}] the admin role's users.manage checkbox was already unchecked before the refused-change attempt`);
    throw Object.assign(new Error('admin role does not hold users.manage before the refusal check'), { dumped: true });
  }

  const rolesLastAdminError = page.locator('[data-test="roles-last-admin-error"]');
  if (await rolesLastAdminError.isVisible()) {
    dumpContainerState(`[${phase}] roles-last-admin-error was already visible before Save was clicked for the admin role`);
    throw Object.assign(new Error('roles-last-admin-error was visible before the refusing click, so it proves nothing about the click'), { dumped: true });
  }

  await adminUsersManageCheckbox.uncheck();
  await adminRow.locator('[data-test="save-role-permissions-button"]').click();

  function adminRoleStillHoldsUsersManage() {
    const php = [
      "$r = \\Spatie\\Permission\\Models\\Role::findByName('admin');",
      "echo 'HOLDS:' . ($r->hasPermissionTo('users.manage') ? 'yes' : 'no');",
    ].join(' ');
    return tinker(php);
  }

  let holdsOutput = adminRoleStillHoldsUsersManage();
  const holdsDeadline = Date.now() + REPLACE_TIMEOUT_MS;
  while (Date.now() < holdsDeadline && !/HOLDS:yes/.test(holdsOutput)) {
    await sleep(POLL_INTERVAL_MS);
    holdsOutput = adminRoleStillHoldsUsersManage();
  }

  if (!/HOLDS:yes/.test(holdsOutput)) {
    dumpContainerState(
      `[${phase}] the admin role no longer holds users.manage after the refused permission change -- raw output: ${holdsOutput}`,
    );
    throw Object.assign(new Error('admin role lost users.manage through a change the guard was supposed to refuse'), { dumped: true });
  }
  console.log(`[${phase}] the admin role still holds users.manage after the attempted change -- the write was refused -- OK`);

  try {
    await rolesLastAdminError.waitFor({ state: 'visible', timeout: 10000 });
  } catch {
    dumpContainerState(
      `[${phase}] the database confirms the refused permission change held, but [data-test="roles-last-admin-error"] never became visible`
      + ' -- the guard held, but the operator would have seen nothing explaining why the click did nothing',
    );
    throw Object.assign(new Error('roles-last-admin-error never became visible after a refused permission change'), { dumped: true });
  }
  console.log(`[${phase}] the last-administrator refusal is visible on the roles page -- OK`);
}

/**
 * issue #214: `AUTORUN_ENABLED=true` (Dockerfile:68) makes
 * docker/entrypoint.d/51-doccum-roles.sh re-run `doccum:ensure-roles` on
 * EVERY boot, including the one tests.yml performs between runSetup() and
 * runVerify() to replace this container. checkAdminRolesPage(), called only
 * from runSetup(), grants `periods.manage` to the `member` role through the
 * real /admin/roles form; this function is the other half -- called from
 * runVerify(), AFTER the replacement, to check that grant is still there.
 *
 * Before the fix, RolesAndPermissionsSeeder::run() ended in
 * syncPermissions() for both roles on every single run of that command,
 * which REPLACES a role's permission set with the seeder's own constants.
 * runSetup()'s grant happened, the same container's next request still saw
 * it (nothing re-ran the seeder in between), and the phase reported green --
 * exactly why this defect needed a check that survives a replacement to be
 * seen at all. Uses tinker rather than the UI: this is a database
 * persistence question, not a rendering one, and re-driving the checkbox
 * would only prove Livewire still works, not that boot left the row alone.
 */
function checkRolePermissionSurvivesContainerReplacement(phase) {
  const php = [
    "$r = \\Spatie\\Permission\\Models\\Role::findByName('member');",
    "echo 'HOLDS:' . ($r && $r->hasPermissionTo('periods.manage') ? 'yes' : 'no');",
  ].join(' ');
  const output = tinker(php);

  if (!/HOLDS:yes/.test(output)) {
    dumpContainerState(
      `[${phase}] the member role no longer holds periods.manage after the container was replaced -- raw output: ${output}`
      + ' -- runSetup() granted it through the real /admin/roles form; something on this'
      + ' boot reset the role\'s permissions back to RolesAndPermissionsSeeder\'s constants (issue #214)',
    );
    throw Object.assign(new Error('member role lost periods.manage across a container restart'), { dumped: true });
  }
  console.log(`[${phase}] the member role still holds periods.manage after the container replacement -- OK`);
}

/**
 * item/upgrade-smoke (issue #210): everything above proves DATA
 * survives a container REPLACEMENT that happens to reuse the exact same
 * image on both sides -- which proves a restart, not an upgrade across a
 * code change. The cheaper substitute to maintaining and pulling an older
 * published image (whose schema only drifts further from HEAD with every
 * migration this repository ships) is to make the CURRENT volume look like
 * it predates the candidate image in the two ways that have actually
 * locked a real instance out before:
 *
 *   - an unverified user (email_verified_at NULL): every account made
 *     before item/email-verification-decided shipped is in exactly this
 *     state. database/migrations/2026_09_18_150000_backfill_email_verified_at_for_existing_users.php
 *     exists to fix that up on upgrade -- see that migration's own
 *     docblock for the full reasoning.
 *   - a role whose permissions an operator edited away from
 *     RolesAndPermissionsSeeder's defaults -- the same class of edit
 *     checkAdminRolesPage()/checkRolePermissionSurvivesContainerReplacement()
 *     above already cover for a GRANT made through the real /admin/roles
 *     form; this covers the opposite direction, a REVOKE made directly
 *     against the database, the way an older instance's history could
 *     equally well have produced it.
 *
 * Called from a DEDICATED workflow step ("Seed pre-upgrade rows before
 * replacing the container"), run against the STILL-RUNNING pre-replacement
 * container, before "Replace the container, keeping only the volume" tears
 * it down -- this is the one point where a write lands on the named volume
 * the replacement is about to reuse, from a container that still holds the
 * live SQLite connection onto it.
 *
 * The migrations-table delete is the detail that makes this a genuine
 * re-run of the migration rather than a restart against a row that merely
 * happens to be NULL. The backfill migration already ran once, as a no-op,
 * the moment THIS container first booted (docker/entrypoint.d migrates
 * before FirstRun has created any user at all -- see 49-doccum-init.sh's
 * own comment on that ordering), and Laravel records a migration as
 * applied in `migrations` and never re-runs it. Inserting a NULL row now
 * and simply restarting would therefore leave that row NULL forever
 * regardless of whether the backfill code exists at all -- an assertion
 * that could never observe the fix being absent is not a check,
 * whatever it asserts. Deleting this ONE migration's own tracking row puts
 * it back in the "not yet run against this data" state a genuinely
 * upgraded instance would be in; the migration's own UPDATE is a plain,
 * idempotent `WHERE email_verified_at IS NULL`, so re-running it is safe.
 * This is exactly what decision/0067's mutation check needs: reverting the
 * migration's up() to a no-op must leave this row NULL even after it is
 * forced to run again -- which is what makes checkStaleUserReachesTheApp()
 * below fail under that mutation, rather than trivially passing because
 * nothing here ever gave the migration a reason to run.
 *
 * No Playwright here at all -- this is a database seed, not a page
 * interaction, so it runs synchronously through tinker() the same way
 * checkRolePermissionSurvivesContainerReplacement() above does.
 */
/**
 * item/upgrade-smoke (issue #210) is the CHECK; item/upgrade-preserves-access,
 * the already-shipped fix, is what it checks. Both cite issue #210 and they are
 * easy to confuse: the backfill migration this seeds against belongs to the
 * second, and this function exists to make the first able to fail.
 */
function seedStaleRows() {
  const php = [
    "$u = \\App\\Models\\User::create(['name' => 'Stale Smoke User',",
    `'username' => '${STALE_USER_USERNAME}', 'email' => '${STALE_USER_EMAIL}',`,
    `'password' => '${STALE_USER_PASSWORD}']);`,
    // User::create() already leaves this NULL -- nothing on this model
    // auto-verifies an account it creates -- but setting it explicitly
    // says outright what "seed a row in the pre-change state" means,
    // rather than resting on an absence this function never actually
    // asked for.
    "$u->forceFill(['email_verified_at' => null])->save();",
    "$deleted = \\Illuminate\\Support\\Facades\\DB::table('migrations')",
    `->where('migration', '${STALE_BACKFILL_MIGRATION}')->delete();`,
    `$role = \\Spatie\\Permission\\Models\\Role::findByName('${STALE_ROLE_NAME}');`,
    // Read BEFORE revoking, same reason checkAdminRolesPage() above checks
    // memberPeriodsCheckbox.isChecked() before toggling it: if
    // RolesAndPermissionsSeeder::MEMBER_PERMISSIONS ever drifted and
    // ${STALE_ROLE_NAME} never held ${STALE_REMOVED_PERMISSION} to begin
    // with, revoking it "succeeds" and HOLDS_AFTER_REVOKE:no would look
    // identical to a real revoke -- proving nothing about survival across
    // the replacement below.
    `$heldBefore = $role->hasPermissionTo('${STALE_REMOVED_PERMISSION}');`,
    `$role->revokePermissionTo('${STALE_REMOVED_PERMISSION}');`,
    "echo 'SEED:' . ($u->email_verified_at === null ? 'unverified' : 'verified')",
    "  . ' MIGRATION_ROW_DELETED:' . $deleted",
    "  . ' HELD_BEFORE_REVOKE:' . ($heldBefore ? 'yes' : 'no')",
    `  . ' HOLDS_AFTER_REVOKE:' . ($role->hasPermissionTo('${STALE_REMOVED_PERMISSION}') ? 'yes' : 'no');`,
  ].join(' ');

  const output = tinker(php);

  if (!/SEED:unverified/.test(output)) {
    dumpContainerState(`seeding the stale user left it verified instead of unverified -- raw output: ${output}`);
    throw Object.assign(new Error('seeded stale user is not actually unverified'), { dumped: true });
  }

  if (!/MIGRATION_ROW_DELETED:1/.test(output)) {
    dumpContainerState(
      `deleting ${STALE_BACKFILL_MIGRATION}'s own row from \`migrations\` did not affect exactly one row -- raw output: ${output}`
      + ' -- either that name no longer matches the migration file, or this ran twice against the same volume',
    );
    throw Object.assign(new Error('did not delete exactly one migrations row for the backfill migration'), { dumped: true });
  }

  if (!/HELD_BEFORE_REVOKE:yes/.test(output)) {
    dumpContainerState(
      `${STALE_ROLE_NAME} did not hold ${STALE_REMOVED_PERMISSION} before this tried to revoke it -- raw output: ${output}`
      + ' -- RolesAndPermissionsSeeder::MEMBER_PERMISSIONS no longer includes it, so revoking it here would prove nothing',
    );
    throw Object.assign(new Error(`${STALE_ROLE_NAME} never held ${STALE_REMOVED_PERMISSION} to begin with`), { dumped: true });
  }

  if (!/HOLDS_AFTER_REVOKE:no/.test(output)) {
    dumpContainerState(`revoking ${STALE_REMOVED_PERMISSION} from ${STALE_ROLE_NAME} did not take -- raw output: ${output}`);
    throw Object.assign(new Error(`${STALE_ROLE_NAME} still holds ${STALE_REMOVED_PERMISSION} right after revoking it`), { dumped: true });
  }

  console.log(
    `[seed-stale] seeded ${STALE_USER_EMAIL} unverified, deleted the backfill migration's own tracking row, `
    + `and revoked ${STALE_REMOVED_PERMISSION} from ${STALE_ROLE_NAME} -- the volume now looks pre-upgrade`,
  );
}

/**
 * item/upgrade-smoke (issue #210): the counterpart to
 * seedStaleRows() above, called from runVerify() AFTER the container has
 * been replaced. Confirms the account seedStaleRows() inserted with
 * email_verified_at NULL -- and whose backfill migration was forced back
 * into a "not yet run against this row" state -- reaches an authenticated
 * page rather than being stranded on Fortify's verification notice.
 *
 * Reports through describeLanding() rather than a bare boolean: CLAUDE.md
 * and this file's own describeLanding() docblock are explicit about why --
 * mutation/0019 cost checkAdminUsersPage() a run where an HTTP 200 read
 * exactly like an authorisation bypass and was actually the verification
 * notice rendering. This check states plainly, on failure, that it is the
 * SAME bounce, so nobody has to rediscover that by reading the source.
 *
 * Logs in and navigates in its own newContext(): this account has no
 * relationship to the admin session the rest of runVerify() drives, and
 * reusing that page could carry a cookie that hides a real failure here.
 */
async function checkStaleUserReachesTheApp(browser, phase) {
  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Username or email', { exact: true }).fill(STALE_USER_EMAIL);
    await page.getByLabel('Password', { exact: true }).fill(STALE_USER_PASSWORD);
    await Promise.all([
      page.waitForURL((u) => u.pathname !== '/login', { timeout: 15000 }),
      page.getByRole('button', { name: 'Log in' }).click(),
    ]);

    // dashboard, not /files or /search: it is the one route behind
    // ['auth', 'verified'] that carries no further `can:` permission, so a
    // bounce here can only be about verification, never about this
    // freshly-created, role-less account lacking some unrelated capability.
    await page.goto(`${BASE_URL}/dashboard`, { waitUntil: 'domcontentloaded' });
    const pathname = new URL(page.url()).pathname;

    if (pathname !== '/dashboard') {
      dumpContainerState(
        // describeLanding() already ends in "instead of the page this was
        // asked for" on a verification bounce, so naming /dashboard again
        // after it produced "instead of ... instead of /dashboard" in the
        // mutation run. The route this asked for is stated up front instead.
        `[${phase}] the user seeded with email_verified_at NULL before the replacement, `
        + `asked for /dashboard after logging in and ${describeLanding(page)} -- `
        + (pathname === VERIFICATION_NOTICE_PATH
          ? 'the backfill migration did not reach this row across the replacement, so it is stranded exactly the way issue #210 describes'
          : 'landed somewhere neither this check nor the login flow expected'),
      );
      throw Object.assign(new Error(`stale user landed on ${pathname} instead of /dashboard`), { dumped: true });
    }

    console.log(
      `[${phase}] the user seeded unverified before the replacement reached ${describeLanding(page)} -- `
      + 'the backfill migration protected it across the upgrade -- OK',
    );
  } finally {
    await context.close();
  }
}

/**
 * item/upgrade-smoke (issue #210), the role-permission half.
 * seedStaleRows() revoked STALE_REMOVED_PERMISSION from STALE_ROLE_NAME
 * directly against the database, on the pre-replacement container -- the
 * same class of edit checkRolePermissionSurvivesContainerReplacement()
 * above already covers for a permission GRANTED through the real
 * /admin/roles form. This is the mirror case: a permission REVOKED must
 * not come BACK after the replacement either, which is exactly what issue
 * #214's fix (RolesAndPermissionsSeeder's createRoleIfMissing() touching
 * nothing on a role that already exists) is supposed to guarantee
 * regardless of which direction the edit went.
 *
 * Pure tinker, like checkRolePermissionSurvivesContainerReplacement()
 * above: this is a database persistence question, not a rendering one.
 */
function checkStaleRolePermissionStaysRevoked(phase) {
  const php = [
    `$r = \\Spatie\\Permission\\Models\\Role::findByName('${STALE_ROLE_NAME}');`,
    `echo 'HOLDS:' . ($r && $r->hasPermissionTo('${STALE_REMOVED_PERMISSION}') ? 'yes' : 'no');`,
  ].join(' ');
  const output = tinker(php);

  if (!/HOLDS:no/.test(output)) {
    dumpContainerState(
      `[${phase}] ${STALE_ROLE_NAME} holds ${STALE_REMOVED_PERMISSION} again after the container was replaced -- raw output: ${output}`
      + ` -- seedStaleRows() revoked it directly against the database before the replacement; something on this boot`
      + ` reset ${STALE_ROLE_NAME}'s permissions back to RolesAndPermissionsSeeder's defaults (issue #214)`,
    );
    throw Object.assign(new Error(`${STALE_ROLE_NAME} regained ${STALE_REMOVED_PERMISSION} across a container restart`), { dumped: true });
  }
  console.log(`[${phase}] ${STALE_ROLE_NAME} still lacks ${STALE_REMOVED_PERMISSION} after the container replacement -- the edit survived -- OK`);
}

/**
 * item/admin-periods (issue #20): closes a finished period through the real
 * /admin/periods form and proves, via tinker, that the resulting
 * ArchivePeriod row now exists and is archived -- CLAUDE.md's own rule,
 * prefer an assertion that requires the feature to DO something over one
 * that observes a resting state.
 *
 * The period closed here (2020-01) is a file dated into it through tinker,
 * built by hand with File::create() rather than File::factory() --
 * checkGrantAndRevokeDirectoryAccess()'s own note above gives the reason:
 * fakerphp/faker is a require-dev dependency (composer.json) and is not
 * autoloadable at all in this --no-dev image, so any ::factory() call
 * fails here even though the feature suite can use it freely against the
 * dev-installed vendor/ tree. Set up through tinker rather than through the
 * upload UI, the same way checkTrashViewRestoreAndPurge() above trashes its
 * targets through tinker to keep its own DOM interaction scoped to the page
 * under test: this check is about closing a period, not uploading, and a
 * real upload would be dated into the CURRENT month, which by definition
 * has not ended.
 *
 * THE LOAD-BEARING ASSERTION (predicted, not proven here -- the mutation is
 * run separately; see the task report): ARCHIVED:yes read back from the
 * database immediately after the click. A broken `can:periods.manage` route
 * guard or a mount()/method-level authorize() that never actually runs
 * would 403 or silently no-op before PeriodCloser::close() is ever called;
 * a Blade form whose wire:model bindings never reached $closeYear/
 * $closeMonth would submit nothing PeriodCloser::close() could use; either
 * way no ArchivePeriod row would exist, or archived_at would stay null,
 * which is exactly what this reads.
 *
 * The DOM checks after it are confirmation only, never the proof, per the
 * same rule: with no retention window configured (config/doccum.php's
 * shipped default), the freshly archived 2020-01 period can never be
 * purgeable, so the doneWhen's "the purge control is disabled ... and
 * lists the blockers" has a real, deterministic case sitting right here to
 * observe.
 */
async function checkAdminPeriodsPage(page, phase) {
  console.log(`[${phase}] creating a file dated into the 2020-01 period through tinker, for /admin/periods to close`);

  const createPhp = [
    `$admin = \\App\\Models\\User::where('username', '${ADMIN_USERNAME}')->first();`,
    "$dir = \\App\\Models\\Directory::where('home_user_id', $admin->id)->first();",
    '$f = \\App\\Models\\File::create([',
    "'directory_id' => $dir->id, 'name' => 'DoccumSmokePeriodTarget.txt',",
    "'mime' => 'text/plain', 'size' => 10, 'checksum' => hash('sha256', 'doccum-smoke-period-target'),",
    "'created_by' => $admin->id, 'period_year' => 2020, 'period_month' => 1,",
    ']);',
    "echo 'CREATED:' . ($f ? 'yes' : 'no');",
  ].join(' ');

  const createOutput = tinker(createPhp);
  if (!createOutput.includes('CREATED:yes')) {
    dumpContainerState(`[${phase}] could not create the scratch 2020-01 file for the admin periods check -- raw output: ${createOutput}`);
    throw Object.assign(new Error('scratch 2020-01 file creation for the admin periods check failed'), { dumped: true });
  }

  function archivePeriodState() {
    const php = [
      "$p = \\App\\Models\\ArchivePeriod::where('year', 2020)->where('month', 1)->first();",
      "echo 'ARCHIVED:' . ($p && $p->isArchived() ? 'yes' : 'no');",
    ].join(' ');
    return tinker(php);
  }

  const before = archivePeriodState();
  if (/ARCHIVED:yes/.test(before)) {
    dumpContainerState(`[${phase}] the 2020-01 period was already archived before the close form was submitted -- raw output: ${before}`);
    throw Object.assign(new Error('2020-01 was already archived before this check ran'), { dumped: true });
  }

  console.log(`[${phase}] opening /admin/periods as the administrator`);
  await page.goto(`${BASE_URL}/admin/periods`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="close-period-form"]').waitFor({ state: 'visible', timeout: 10000 });

  console.log(`[${phase}] closing 2020-01 through the real close-period form`);
  // getByLabel(), not a data-test locator: flux:input renders a
  // label/wrapper around the real <input>, and there is no precedent in
  // this codebase for an attribute placed on flux:input landing on that
  // inner element in the built image -- see the Blade view's own note.
  await page.locator('[data-test="close-period-form"]').getByLabel('Year', { exact: true }).fill('2020');
  await page.locator('[data-test="close-period-form"]').getByLabel('Month (optional -- leave blank for the whole year)', { exact: true }).fill('1');
  await clickAndWaitForLivewire(page, page.locator('[data-test="close-period-button"]'));

  const after = archivePeriodState();
  if (!/ARCHIVED:yes/.test(after)) {
    dumpContainerState(`[${phase}] closing 2020-01 through /admin/periods never archived it -- raw output: ${after}`);
    throw Object.assign(new Error('2020-01 was not archived after closePeriod() through the real form'), { dumped: true });
  }
  console.log(`[${phase}] 2020-01 is archived after closing it through the real form -- OK`);

  const row = page.locator('[data-test="period-row"][data-year="2020"][data-month="1"]');

  try {
    await row.locator('[data-test="period-status"]').filter({ hasText: 'Archived' }).waitFor({ timeout: 10000 });
  } catch {
    dumpContainerState(`[${phase}] the database confirms 2020-01 is archived, but its row never shows "Archived" on /admin/periods`);
    throw Object.assign(new Error('period-status never showed Archived for 2020-01 after closing it'), { dumped: true });
  }

  // Confirmation only, per the docblock above.
  const purgeButton = row.locator('[data-test="purge-period-button"]');
  if (!(await purgeButton.isDisabled())) {
    dumpContainerState(`[${phase}] the purge button for 2020-01 is NOT disabled even though no retention window is configured, so plan()->purgeable must be false`);
    throw Object.assign(new Error('purge-period-button was enabled for a period that cannot be purgeable'), { dumped: true });
  }

  const blockersText = (await row.locator('[data-test="period-blockers"]').innerText()).trim();
  if (!blockersText.includes('retention window')) {
    dumpContainerState(`[${phase}] 2020-01's blockers list does not mention the retention window -- raw text: "${blockersText}"`);
    throw Object.assign(new Error('period-blockers did not list the missing-retention-window blocker'), { dumped: true });
  }
  console.log(`[${phase}] the purge control is disabled and lists the retention-window blocker for 2020-01 -- OK`);
}

/**
 * item/admin-instance-settings (issue #21). Follows checkAdminPeriodsPage()
 * above exactly: failures dump container state naming what was being
 * proved and throw with { dumped: true }; database/HTTP evidence is the
 * assertion, DOM checks are confirmation afterwards.
 *
 * The load-bearing assertion is the doneWhen itself, verbatim: toggling
 * auth.public_signup through the real form flips /register between 404 and
 * 200. That is checked from a SEPARATE, cookie-less browser context --
 * never from `page`, which is authenticated as the administrator for the
 * whole of this script -- because /register is a guest-only route and the
 * fact this proves is what an anonymous visitor sees, not what an
 * authenticated admin sees. A Blade test can assert the same thing against
 * the test renderer; it cannot see this route wired up, this exact
 * middleware registered, and this exact page's Save button reaching it, all
 * inside the built image.
 *
 * The instance-name round trip is a second, weaker confirmation -- read
 * back through tinker, which is DOM-adjacent evidence, not the resting
 * state of an input.
 *
 * Public signup is switched back off at the end, on the SAME guest-facing
 * fact this check started from: later checks in this script share this
 * container, and one that left signup on would change what every check
 * after it is running against.
 */
async function checkAdminInstanceSettingsPage(page, phase) {
  const guestContext = await page.context().browser().newContext();

  try {
    const guestPage = await guestContext.newPage();

    function registerStatus() {
      return guestPage.goto(`${BASE_URL}/register`, { waitUntil: 'domcontentloaded' });
    }

    console.log(`[${phase}] checking /register answers 404 for a guest before public sign-up is enabled`);
    let response = await registerStatus();
    let status = response ? response.status() : null;

    if (status !== 404) {
      dumpContainerState(`[${phase}] /register answered HTTP ${status} for a guest before this check touched auth.public_signup -- expected 404`);
      throw Object.assign(new Error(`/register did not start this check at 404, answered ${status}`), { dumped: true });
    }
    console.log(`[${phase}] /register answers 404 for a guest -- OK`);

    console.log(`[${phase}] opening /admin/settings as the administrator`);
    await page.goto(`${BASE_URL}/admin/settings`, { waitUntil: 'domcontentloaded' });
    await page.locator('[data-test="instance-signup-form"]').waitFor({ state: 'visible', timeout: 10000 });

    console.log(`[${phase}] enabling public sign-up through the real form`);
    // getByLabel(), not a data-test locator: flux:checkbox renders a
    // label/wrapper around the real <input>, the same reasoning the
    // close-period form's own note in the Blade view gives for flux:input.
    await page.locator('[data-test="instance-signup-form"]').getByLabel('Allow anyone to create an account', { exact: true }).check();
    await clickAndWaitForLivewire(page, page.locator('[data-test="instance-signup-save-button"]'));

    console.log(`[${phase}] checking /register now answers 200 for that same guest`);
    response = await registerStatus();
    status = response ? response.status() : null;

    if (status !== 200) {
      dumpContainerState(`[${phase}] enabling auth.public_signup through /admin/settings never made /register answer 200 for a guest -- got HTTP ${status}`);
      throw Object.assign(new Error(`/register did not become reachable after enabling public sign-up through the page, answered ${status}`), { dumped: true });
    }
    console.log(`[${phase}] /register answers 200 for a guest after enabling public sign-up through the page -- OK (the doneWhen)`);

    console.log(`[${phase}] round-tripping the instance name through the real form`);
    const instanceNameField = page.locator('[data-test="instance-name-form"]').getByLabel('Instance name', { exact: true });
    await instanceNameField.fill('Doccum Smoke Instance');
    await clickAndWaitForLivewire(page, page.locator('[data-test="instance-name-save-button"]'));

    const storedName = tinker("echo 'NAME:' . app(\\App\\Services\\Settings::class)->get('instance.name');").trim();
    if (!storedName.includes('NAME:Doccum Smoke Instance')) {
      dumpContainerState(`[${phase}] saving the instance name through /admin/settings did not reach the Settings service -- raw output: ${storedName}`);
      throw Object.assign(new Error(`instance name was not persisted through Settings::set() -- raw output: ${storedName}`), { dumped: true });
    }
    console.log(`[${phase}] the instance name round-trips through the Settings service -- OK`);

    console.log(`[${phase}] turning public sign-up back off, so later checks share the container in its original state`);
    await page.goto(`${BASE_URL}/admin/settings`, { waitUntil: 'domcontentloaded' });
    await page.locator('[data-test="instance-signup-form"]').getByLabel('Allow anyone to create an account', { exact: true }).uncheck();
    await clickAndWaitForLivewire(page, page.locator('[data-test="instance-signup-save-button"]'));

    response = await registerStatus();
    status = response ? response.status() : null;

    if (status !== 404) {
      dumpContainerState(`[${phase}] disabling auth.public_signup through /admin/settings left /register answering HTTP ${status} for a guest -- expected 404`);
      throw Object.assign(new Error(`/register did not go back to 404 after disabling public sign-up through the page, answered ${status}`), { dumped: true });
    }
    console.log(`[${phase}] /register answers 404 for a guest again -- signup left off for later checks -- OK`);
  } finally {
    await guestContext.close();
  }
}

/**
 * item/api-sanctum-tokens (issue #22): drives /settings/api-tokens the way
 * an operator would. tests/Feature/Settings/ApiTokensTest.php and its
 * mutation-proven guard on App\Livewire\Settings\ApiTokens already prove the
 * component against the test renderer -- what only a real browser against
 * the built image can see is whether the real form reaches
 * ApiTokens::createToken() at all (a broken asset build or an unresolved
 * Flux component leaves every Blade assertion green and the control
 * unusable, same class of gap every other admin/settings check in this file
 * exists to catch).
 *
 * Two doing-assertions, not resting-state ones (CLAUDE.md's account-menu
 * lesson): creating a token and reading the plaintext value BACK OFF THE
 * PAGE (never a fixed placeholder -- an assertion against a hardcoded
 * string would pass even if $plainTextToken were wired to something else
 * entirely) requires the form to have submitted, ApiTokens::createToken()
 * to have run and saved a row, and the component to have re-rendered with a
 * value that exists only in memory; reloading and finding that SAME string
 * gone requires a fresh mount() to come back without it
 * (App\Livewire\Settings\ApiTokens's own docblock: mount() never
 * repopulates $plainTextToken -- there is nowhere in the database for it to
 * be read back from).
 *
 * /settings/api-tokens sits behind the same `password.confirm` middleware as
 * /settings/security (routes/settings.php) -- a gate this smoke has never
 * driven before. Whether it appears depends on how recently this session
 * confirmed its password (Fortify's password-timeout), so this checks for
 * the confirm form's own [data-test] rather than assuming either outcome: if
 * it is there, it fills the ONE password field
 * (resources/views/livewire/auth/confirm-password.blade.php -- a plain
 * `<form method="POST">`, not a Livewire component, so a real navigation
 * follows the click) and waits for the ORIGINAL destination, not a bare
 * "the URL changed", since Laravel's RequirePassword middleware stores the
 * intended URL and Fortify's confirmation controller redirects back to it.
 */
async function checkApiTokensPage(page, phase) {
  console.log(`[${phase}] opening /settings/api-tokens as the administrator`);
  await page.goto(`${BASE_URL}/settings/api-tokens`, { waitUntil: 'domcontentloaded' });

  const confirmButton = page.locator('[data-test="confirm-password-button"]');
  if (await confirmButton.isVisible().catch(() => false)) {
    console.log(`[${phase}] /settings/api-tokens bounced to the password-confirm gate -- confirming with the admin's password`);
    await page.getByLabel('Password', { exact: true }).fill(ADMIN_PASSWORD);
    await Promise.all([
      page.waitForURL((u) => u.pathname === '/settings/api-tokens', { timeout: 15000 }),
      confirmButton.click(),
    ]);
    console.log(`[${phase}] password-confirm gate cleared, back at /settings/api-tokens -- OK`);
  } else {
    console.log(`[${phase}] no password-confirm gate this time -- the session already held a recent confirmation`);
  }

  await page.locator('[data-test="create-token-form"]').waitFor({ state: 'visible', timeout: 10000 });

  const digits = Date.now().toString().slice(-9);
  const tokenName = `Smoke Token ${digits}`;

  console.log(`[${phase}] creating a token named "${tokenName}" through the real form`);
  await page.getByLabel('Name', { exact: true }).fill(tokenName);
  await page.locator('[data-test="ability-checkbox"]').first().check();
  await clickAndWaitForLivewire(page, page.locator('[data-test="create-token-button"]'));

  const tokenValueLocator = page.locator('[data-test="new-token-value"]');
  try {
    await tokenValueLocator.waitFor({ state: 'visible', timeout: 10000 });
  } catch {
    dumpContainerState(
      `[${phase}] submitting the create-token form for "${tokenName}" never produced [data-test="new-token-value"]`
      + ' -- either the submit never reached ApiTokens::createToken(), or the component did not re-render with a plaintext value',
    );
    throw Object.assign(new Error('creating a token through the real form produced no plaintext value on the page'), { dumped: true });
  }

  const plainTextToken = (await tokenValueLocator.textContent())?.trim();
  if (!plainTextToken) {
    dumpContainerState(`[${phase}] [data-test="new-token-value"] rendered but held no text after creating "${tokenName}"`);
    throw Object.assign(new Error('new-token-value rendered empty after creating a token'), { dumped: true });
  }
  console.log(`[${phase}] the plaintext token appeared on the page right after creating it -- OK`);

  console.log(`[${phase}] reloading /settings/api-tokens and checking that exact plaintext value is gone`);
  await page.goto(`${BASE_URL}/settings/api-tokens`, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-test="tokens-list"]').waitFor({ state: 'visible', timeout: 10000 });

  const stillVisible = await page.getByText(plainTextToken, { exact: true }).isVisible().catch(() => false);
  if (stillVisible) {
    dumpContainerState(`[${phase}] the plaintext token captured off "${tokenName}"'s creation is still on the page after a reload of /settings/api-tokens`);
    throw Object.assign(new Error('plaintext token value survived a reload of /settings/api-tokens'), { dumped: true });
  }
  console.log(`[${phase}] the plaintext token is gone after reloading -- OK`);
}

/**
 * Polls the search page for an exact name, the way searchUntilFound() above
 * polls for FILE_MARKER -- kept as its own function, rather than a shared
 * helper, so as not to touch searchUntilFound() itself (see the note at the
 * top of this file: another branch is concurrently editing the extraction
 * poller, and this stays clear of that region and of tinker() by construction).
 */
async function searchUntilFoundByName(page, name) {
  // Navigate first. searchUntilFound() above is only ever called with the
  // browser already on /search; this one is called straight after an upload,
  // with the browser still in the files browser, where there is no Search
  // field at all -- so it timed out on locator.fill waiting for a control
  // that was never going to appear. The post-trash search further down
  // already does this goto; only this half was missing it.
  await page.goto(`${BASE_URL}/search`, { waitUntil: 'domcontentloaded' });

  const deadline = Date.now() + SEARCH_TIMEOUT_MS;
  const field = page.getByLabel('Search', { exact: true });

  // Type the name WITHOUT its extension. Terms::words() splits on anything
  // that is not a letter, digit or underscore, so "X.txt" becomes the two
  // terms "X" and "txt" ANDed together (app/Search/Terms.php); the stem
  // alone is one term and cannot fail on the join.
  const stem = name.replace(/\.[^.]+$/, '');

  while (Date.now() < deadline) {
    await field.fill('');
    await field.fill(stem);

    const found = await page
      .getByText(name, { exact: true })
      .waitFor({ timeout: 2000 })
      .then(() => true)
      .catch(() => false);

    if (found) return;
  }

  // Say WHICH of the three things is missing rather than only that the search
  // came back empty: the file row, its search projection, or the match. Two
  // runs were spent on this check without knowing which.
  let state = '(could not be read)';
  try {
    state = tinker([
      `$f = \\App\\Models\\File::where('name', '${name}')->first();`,
      "if (!$f) { echo 'FILE:no'; } else {",
      "echo 'FILE:yes';",
      "$d = \\App\\Models\\SearchDocument::where('subject_type', 'file')->where('subject_id', $f->id)->first();",
      "echo ' DOC:' . ($d ? 'yes title=[' . $d->title . ']' : 'no');",
      '}',
    ].join(' ')).trim();
  } catch (e) {
    state = e.message;
  }

  dumpContainerState(
    `search never found ${name} (typed "${stem}") within ${SEARCH_TIMEOUT_MS}ms\n  container says: ${state}`,
  );
  throw Object.assign(new Error(`search timed out for ${name}; container says: ${state}`), { dumped: true });
}

/**
 * item/upload-silent-discard (issue #106): the instrumentation that should
 * have existed before anything was concluded about that bug.
 *
 * #106 has been described from the beginning -- in the issue, in several
 * comments, in decision/0026 and in this file's own comments -- as an upload
 * discarded with "no request, no error". Neither half was ever observed.
 * This script carried no page.on() listeners of any kind, so it has never
 * captured a request or a console message; what was actually seen is that no
 * files row appeared and that the form's submit button was left disabled.
 * "No request" is a GUESS ABOUT THE CAUSE that has been steering every
 * workaround for three runs, and it points diagnosis at "the request never
 * left the browser" when the evidence fits equally well a request that was
 * made and answered with its state lost. decision/0027 records the
 * correction.
 *
 * So this attached listeners and said nothing about what they would show.
 * Three different bugs had been treated as one, and one instrumented run was
 * to separate them:
 *
 *   never sent      -- no upload request appears at all
 *   sent and failed -- a request appears and fails, or answers 4xx/5xx
 *   state lost      -- a request appears, answers 2xx, and no row exists
 *
 * THE ANSWER, from run 35290482556, which caught the discard three times:
 * "state lost", and the mechanism is a race with the upload itself. Every
 * discarded upload had its POST to the upload endpoint answer in ~550ms and
 * the store() commit issued ~14ms after it, while the upload was still in
 * flight. Every stored one had the upload answer in ~18ms and the commit
 * issued after it. The submit click was racing the file input's own upload,
 * so store() ran against a property Livewire had not populated, failed
 * `required|file`, and the _finishUpload commit re-rendered over the error.
 * Neither "never sent" nor "sent and failed" was the bug, and the retries
 * that hid it worked only because the second attempt ran after the upload
 * had landed. Fixed in resources/views/livewire/files/browser.blade.php by
 * disabling the submit button for the duration of the upload, and asserted
 * by checkUploadButtonIsDisabledWhileTheFileIsStillUploading().
 *
 * Output is prefixed [upload-probe] so it can be grepped out of a job log
 * without reading the whole thing, and is deliberately noisy rather than
 * summarised: the summary is the thing that had been wrong.
 */
function instrumentUploadPath(page) {
  // Everything that is not a static asset, NOT a guessed endpoint prefix.
  //
  // The first version of this filtered to '/livewire/' and logged nothing at
  // all across a run that drives the installer, search and the file browser
  // -- every one of them a Livewire component, and three uploads that issue
  // #106 swallowed. Zero matches across all of that says the filter is wrong,
  // not that no requests were made.
  //
  // run/0014 already recorded this exact mistake: waitForResponse() against a
  // guessed '/livewire/upload-file' became the failure it was meant to
  // observe. Guessing the same prefix again is how one CI round of a
  // two-round budget was spent. An instrument may not assume the shape of
  // what it is measuring.
  const isAsset = (url) => /\.(js|mjs|css|png|jpe?g|svg|ico|woff2?|ttf|map)(\?|$)/i.test(url);

  page.on('request', (r) => {
    if (! isAsset(r.url())) {
      console.log(`[upload-probe] request ${r.method()} ${r.url()}`);
    }
  });

  page.on('requestfailed', (r) => {
    console.log(`[upload-probe] REQUEST FAILED ${r.method()} ${r.url()} -- ${r.failure()?.errorText ?? 'no reason given'}`);
  });

  page.on('response', (r) => {
    if (! isAsset(r.url())) {
      console.log(`[upload-probe] response ${r.status()} ${r.url()}`);
    }
  });

  page.on('console', (m) => {
    if (m.type() === 'error' || m.type() === 'warning') {
      console.log(`[upload-probe] console.${m.type()}: ${m.text().slice(0, 300)}`);
    }
  });

  page.on('pageerror', (e) => {
    console.log(`[upload-probe] pageerror: ${String(e.message).split('\n')[0].slice(0, 300)}`);
  });
}

async function runSetup() {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();

    // Kept after issue #106 was fixed, and deliberately so. Its first round
    // filtered requests to a GUESSED "/livewire/" prefix and printed nothing
    // while the bug struck three times; the second round filtered nothing but
    // assets and the timestamps alone named the cause in one run, after three
    // rounds of reasoning had not. It costs a few hundred log lines that only
    // matter when something is wrong, which is exactly when they are wanted.
    instrumentUploadPath(page);

    console.log(`[setup] opening ${BASE_URL}/setup`);
    await page.goto(`${BASE_URL}/setup`, { waitUntil: 'domcontentloaded' });

    // Step 3 is the ONLY step shown by default: FirstRun::$step defaults to 3
    // and $advanced defaults to false (see app/Livewire/Setup/FirstRun.php),
    // because the embedded database and embedded storage need no operator
    // input at all. Driving steps 1/2 is deliberately not exercised here --
    // there is nothing for this smoke test to configure.
    await page.getByLabel('Instance name', { exact: true }).fill(INSTANCE_NAME);
    await page.getByLabel('Name', { exact: true }).fill(ADMIN_NAME);
    await page.getByLabel('Username', { exact: true }).fill(ADMIN_USERNAME);
    await page.getByLabel('Email address', { exact: true }).fill(ADMIN_EMAIL);
    await page.getByLabel('Password', { exact: true }).fill(ADMIN_PASSWORD);
    await page.getByLabel('Confirm password', { exact: true }).fill(ADMIN_PASSWORD);

    console.log('[setup] submitting the administrator form');
    try {
      await Promise.all([
        // submit() ends in `redirect()->route('files.browse')` (see
        // FirstRun::submit) -- a real, full-page redirect, not a Livewire
        // ->navigate() morph, so a plain URL wait is enough.
        //
        // This wait is the whole of issue #97's evidence. Put the redirect back
        // to '/' and it times out here, because '/' is Route::view('/',
        // 'welcome') -- Laravel's starter page. The page.goto() below cannot
        // rescue it: this wait runs first.
        //
        // It is ALSO, since item/email-verification-decided (issue #161),
        // the whole of mutation/0018's evidence: `files.browse` carries the
        // `verified` middleware, so any change that leaves the first
        // administrator unverified fails HERE, by construction, before
        // control is even handed back to this script -- there used to be a
        // separate checkFirstAdminIsVerified() function, called right after
        // this Promise.all, that tried to name that failure on its own
        // tinker/DOM evidence. item/smoke-installer-names-the-bounce
        // (issue #197) removed it, because mutation/0018 showed it could
        // never be the check a red run points at -- this wait always fails
        // first. The catch block below is
        // what makes THIS failure legible instead of a bare "Timeout
        // 15000ms exceeded" -- unverified, the redirect bounces to Fortify's
        // verification notice, and describeLanding() (mutation/0019, above
        // fileRowExists()) names that explicitly instead of leaving a bare
        // timeout, or a raw URL, open to being misread the way an HTTP 200
        // was misread as a bypass elsewhere in this file.
        page.waitForURL((u) => u.pathname === '/files', { timeout: 15000 }),
        page.getByRole('button', { name: 'Create administrator account' }).click(),
      ]);
    } catch {
      dumpContainerState(
        `[setup] the installer never reached /files after submitting the administrator form -- ${describeLanding(page)}.`
        + ' A bounce to the verification notice here means the first administrator was not verified at'
        + ' creation (item/email-verification-decided, issue #161); a bounce anywhere else (e.g. back to'
        + " '/', Laravel's starter welcome page) is issue #97's original failure mode instead.",
      );
      throw Object.assign(
        new Error(`installer did not redirect to /files -- ${describeLanding(page)}`),
        { dumped: true },
      );
    }
    console.log(`[setup] installer complete, admin created and logged in -- ${describeLanding(page)}`);

    checkEmbeddedSqlitePragmas();

    console.log('[setup] opening the home directory');
    await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });

    // Checked here rather than on the post-installer redirect: that lands on
    // `/`, which is Route::view('/', 'welcome') -- a standalone document that
    // does not use the topbar layout, so there would be no topbar to drive.
    await checkTopbar(page, 'setup');
    await page.keyboard.press('Escape');

    // CreateHomeDirectory names the admin's own directory after their
    // username (config('doccum.settings.directories.auto_home') defaults to
    // true -- see config/doccum.php). item/files-three-pane (issue #104/#99)
    // put the SAME reach roots in the sidebar and in this landing-pane
    // listing, so the directory's name is no longer a unique link on this
    // page on its own -- '[data-test="directories-list"]' below (see
    // resources/views/livewire/files/browser.blade.php) says which of the
    // two this means, everywhere else in this file that clicks it too.
    await page.locator('[data-test="directories-list"]').getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
    await page.locator('[data-test="upload-form"] input[type="file"]').waitFor({ state: 'attached', timeout: 10000 });

    console.log('[setup] checking the sidebar/breadcrumb tree navigation two levels deep (issue #104/#99)');
    await checkBreadcrumbNavigatesTwoLevels(page, 'setup');

    console.log('[setup] stalling the upload endpoint to check the Upload button is disabled in flight (issue #106)');
    await checkUploadButtonIsDisabledWhileTheFileIsStillUploading(page, 'setup');

    console.log(`[setup] uploading ${FILE_NAME}`);
    await uploadAndProveStored(
      page,
      FILE_NAME,
      `This is a doccum container smoke test document containing the marker word ${FILE_MARKER}.\n`,
      'setup',
    );

    console.log('[setup] checking Home lists the just-uploaded file under Recent files (issue #16)');
    await checkHomeDashboardShowsRecentUpload(page, 'setup');

    console.log(`[setup] waiting up to ${EXTRACTION_TIMEOUT_MS}ms for extraction to finish`);
    await waitForExtraction();
    console.log('[setup] extraction reached "done"');

    console.log('[setup] searching for the document by a word inside it');
    await page.goto(`${BASE_URL}/search`, { waitUntil: 'domcontentloaded' });
    await searchUntilFound(page, 'setup');
    console.log('[setup] search found the uploaded document -- OK');

    console.log('[setup] checking the search page\'s period filter actually narrows results (issue #17)');
    await checkSearchFilterExcludesByPeriod(page, 'setup');

    console.log('[setup] opening the command palette with Ctrl-K from /files, searching in it, and opening a hit with Enter');
    await checkCommandPaletteOpensSearchesAndOpensAHit(page, 'setup');

    console.log('[setup] trashing a file through the detail panel and confirming it disappears from the listing and from search');
    await checkTrashRemovesFileFromListingAndSearch(page, 'setup');

    console.log('[setup] replacing a file through the detail panel and confirming a second version appears');
    await checkReplaceAddsASecondVersion(page, 'setup');

    console.log('[setup] following a Download link and checking the bytes come back (issue #74)');
    await checkDownloadReturnsTheUploadedBytes(page, 'setup');

    console.log('[setup] opening a file in the preview dialog and reading its bytes back out of the frame');
    await checkFilePreviewShowsTheFileContents(page, 'setup');

    console.log('[setup] bulk-trashing two of three uploaded files and confirming the third survives');
    await checkBulkTrashLeavesUnselectedFilesAlone(page, 'setup');

    console.log('[setup] granting and revoking directory access through the detail panel (issue #14)');
    await checkGrantAndRevokeDirectoryAccess(page, 'setup');

    console.log('[setup] restoring and purging trashed files through the new /trash page (issue #15)');
    await checkTrashViewRestoreAndPurge(page, 'setup');

    console.log('[setup] creating a user through /admin/users and checking its home directory, its 403 for a non-admin, and the last-administrator guard (issue #18)');
    await checkAdminUsersPage(page, 'setup');

    console.log('[setup] toggling a role permission through /admin/roles and checking the last-administrator guard on a role edit (issue #19)');
    await checkAdminRolesPage(page, 'setup');

    console.log('[setup] closing a period through /admin/periods and checking the purge control is disabled and lists its blockers (issue #20)');
    await checkAdminPeriodsPage(page, 'setup');

    console.log('[setup] toggling auth.public_signup through /admin/settings and checking /register flips between 404 and 200 for a guest (issue #21)');
    await checkAdminInstanceSettingsPage(page, 'setup');

    console.log('[setup] creating a personal access token through /settings/api-tokens and checking its plaintext value appears once and is gone on reload (issue #22)');
    await checkApiTokensPage(page, 'setup');

    console.log('[setup] checking the password-reset URL honours a forwarded proto/host');
    checkForwardedPasswordResetUrl();

    console.log('[setup] checking doccum:user:reset-password prints a working link with no mailer configured');
    await checkResetPasswordCommandPrintsAWorkingLink(browser, 'setup');

    console.log('[setup] checking forgot-password says mail is not configured, identically, for a real and a nonexistent account');
    await checkForgotPasswordSameResponseRegardlessOfAccount(browser, 'setup');
  } finally {
    await browser.close();
  }
}

async function runVerify() {
  const browser = await chromium.launch();
  try {
    // A brand new context: no cookies carried over from the setup phase, so
    // reaching the file genuinely proves the DATA survived the container
    // being replaced, not just that this process kept a session alive.
    const context = await browser.newContext();
    const page = await context.newPage();

    // item/login-by-username (issue #75): logs in with the USERNAME here,
    // not ADMIN_EMAIL, so this is the one place the smoke actually drives
    // AuthenticateUser's username lookup through a browser rather than only
    // through a Pest test that renders the view with the test renderer --
    // see CLAUDE.md on why a Blade assertion cannot see a broken field type,
    // a stale autocomplete token, or a login.blade.php that still rejects a
    // username at type="email" before the request is ever sent.
    console.log('[verify] logging back in with the USERNAME against the replacement container');
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Username or email', { exact: true }).fill(ADMIN_USERNAME);
    await page.getByLabel('Password', { exact: true }).fill(ADMIN_PASSWORD);
    try {
      await Promise.all([
        page.waitForURL((u) => u.pathname !== '/login', { timeout: 15000 }),
        page.getByRole('button', { name: 'Log in' }).click(),
      ]);
    } catch {
      // Named, not a bare waitForURL timeout. The mutation for this item
      // (AuthenticateUser pinned back to email-only) failed here with
      // nothing but "page.waitForURL: Timeout 15000ms exceeded", readable
      // only because the console line above happens to say USERNAME. Rule 3
      // of the Mutation vocabulary says a failure that names no assertion is
      // not evidence, and this one is worth naming precisely: every login in
      // the setup phase is by EMAIL and they all still passed under that
      // mutation, so being stranded HERE is what distinguishes "username
      // resolution is broken" from "login is broken".
      dumpContainerState(
        `[verify] ${ADMIN_USERNAME} could not log in by username -- still on /login.`
        + ' Every email login earlier in this run succeeded, so this is the'
        + ' username branch of AuthenticateUser specifically, not login at large',
      );
      throw Object.assign(
        new Error(`login by username (${ADMIN_USERNAME}) never left /login`),
        { dumped: true },
      );
    }

    console.log('[verify] logged in by username -- the admin account persisted');

    console.log('[verify] checking the member role still holds the periods.manage permission granted during setup, across the container replacement (issue #214)');
    checkRolePermissionSurvivesContainerReplacement('verify');

    console.log('[verify] checking a role permission revoked directly against the database before the replacement stayed revoked (issue #210/#214)');
    checkStaleRolePermissionStaysRevoked('verify');

    console.log('[verify] checking a user seeded with email_verified_at NULL before the replacement still reaches the app instead of being stranded on the verification notice (issue #210)');
    await checkStaleUserReachesTheApp(browser, 'verify');

    await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
    await page.locator('[data-test="directories-list"]').getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
    await page.getByText(FILE_NAME, { exact: true }).waitFor({ timeout: 10000 });
    console.log('[verify] the uploaded file is still listed -- the DATABASE persisted');


    await page.goto(`${BASE_URL}/search`, { waitUntil: 'domcontentloaded' });
    await searchUntilFound(page, 'verify');
    console.log('[verify] search still finds it -- the search index persisted -- OK');

    // LAST of the three persistence assertions, deliberately. The listing and
    // the search hit above are both SQLite reads, and for this phase's whole
    // life the listing carried the log line
    // "object storage persisted". Neither of them ever touched an object, and
    // checkDownloadReturnsTheUploadedBytes() runs in setup only. So
    // the one claim this phase exists to make -- that /data is the only
    // persistent volume and everything on it survives the container being
    // replaced -- was asserted from evidence that cannot see it. A container
    // that came back with /data/objects emptied, or minio.env lost, or the
    // bucket gone, passed this phase green while reporting the opposite of
    // what it had measured. Running this check AFTER both of them is what
    // lets the mutation show that: with object storage destroyed, both of
    // the assertions above still pass and only this one reddens.
    // Dockerfile:85 records the MIRROR of that failure
    // (database in the image layer, objects on the volume, "half-alive"),
    // which this phase did catch, precisely because it reads the database.
    //
    // CLAUDE.md: a smoke assertion is worth only what a mutation says it is,
    // and prefer an assertion that requires the feature to DO something. So
    // this now fetches the bytes. The body is deterministic from the
    // environment -- runSetup() uploads exactly this string -- so the
    // checksum is computable here without carrying state between phases,
    // which is the constraint this file is built around.
    const persistedBody = `This is a doccum container smoke test document containing the marker word ${FILE_MARKER}.\n`;
    const persistedSha = crypto.createHash('sha256').update(persistedBody).digest('hex');

    // Back to the listing first. downloadAndCompareBytes() reads the row's
    // own Download href rather than constructing a URL from an id, so it has
    // to be ON a listing that shows the row -- and the search assertion above
    // leaves the browser at /search, which has no file rows at all. Ordering
    // this check last is what made the navigation necessary, and the first
    // attempt at that reorder dropped it: "locator.waitFor: Timeout 10000ms
    // exceeded ... tr[data-test="file-row"] filter hasText smoke-...txt".
    await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
    await page.locator('[data-test="directories-list"]').getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();

    await downloadAndCompareBytes(page, FILE_NAME, persistedSha, 'verify');
    console.log('[verify] the bytes came back from the replacement container -- OBJECT STORAGE persisted');

    // Last, because it ends the session. Logout is the one topbar control
    // with a server-side effect, so it is the one that proves the dropdown's
    // form -- and its CSRF token -- survive in the container rather than
    // merely rendering.
    const logout = await checkTopbar(page, 'verify');
    await Promise.all([
      // Where Fortify's logout response lands is Fortify's business, not
      // doccum's, so this waits only for "somewhere other than /search".
      // Pinning it to one path would make this check fail on an upgrade that
      // changed nothing doccum owns.
      page.waitForURL((u) => u.pathname !== '/search', { timeout: 15000 }),
      logout.click(),
    ]);

    // Leaving /search proves only that the form posted. Asking for an
    // authenticated page afterwards is what proves the session was actually
    // destroyed.
    await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
    await page.waitForURL((u) => u.pathname === '/login', { timeout: 10000 });
    console.log('[verify] logged out through the account menu, session gone -- OK');
  } finally {
    await browser.close();
  }
}

(async () => {
  try {
    if (phase === 'setup') {
      await runSetup();
    } else if (phase === 'seed-stale') {
      seedStaleRows();
    } else {
      await runVerify();
    }
  } catch (error) {
    console.error(`\n::error::container-smoke (${phase}) failed: ${error.message}`);
    if (!error.dumped) dumpContainerState(`container-smoke (${phase}) failing state`);
    process.exit(1);
  }
})();

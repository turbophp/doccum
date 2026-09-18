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
// Run as: node .github/scripts/container-smoke.mjs <setup|verify>
//   setup  -- complete the installer, check the topbar shell, upload a file,
//             wait for extraction, confirm it is findable by search. Run
//             once, against a freshly booted, empty-volume container.
//   verify -- log in again and confirm the file and its search hit survived
//             the container being replaced, then log out through the account
//             menu. Run in a fresh browser context (no cookies carried over),
//             against a NEW container started from the same image on the same
//             named volume -- not `docker restart`, which would keep the old
//             container's writable layer and prove nothing (issue #91).
//
// All credentials and the marker text searched for come from the environment
// (set by the workflow step that invokes this), so both phases agree on them
// without this script persisting any state of its own between invocations.

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

const EXTRACTION_TIMEOUT_MS = Number(env('EXTRACTION_TIMEOUT_MS', '60000'));
const POLL_INTERVAL_MS = Number(env('POLL_INTERVAL_MS', '2000'));
const SEARCH_TIMEOUT_MS = Number(env('SEARCH_TIMEOUT_MS', '20000'));
const REPLACE_TIMEOUT_MS = Number(env('REPLACE_TIMEOUT_MS', '20000'));

// Only used by checkForwardedPasswordResetUrl() below (item/reverse-proxy-ready,
// issue #58), which only the 'setup' phase calls -- read with a fallback here
// so the 'verify' phase (whose step sets no such env var at all) never trips
// env()'s "missing required variable" check just by loading this module.
const SMOKE_FORWARDED_HOST = env('SMOKE_FORWARDED_HOST', '');

const phase = process.argv[2];
if (phase !== 'setup' && phase !== 'verify') {
  console.error('Usage: node container-smoke.mjs <setup|verify>');
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
 */
async function clickOnceUploadSettles(page, button, phase, label) {
  let sawDisabled = false;
  for (let waited = 0; waited < 5000; waited += 50) {
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
 * exists for it. ONE attempt: no retry, no second chance.
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
 * made. Retrying here would hide a regression of exactly that, so this no
 * longer retries -- if the guard is removed or Flux stops forwarding it, some
 * upload in this run stores nothing and the run says so.
 */
async function uploadAndProveStored(page, name, contents, phase) {
  const tmpFile = path.join(os.tmpdir(), name);
  fs.writeFileSync(tmpFile, contents);

  await page.locator('[data-test="upload-form"] input[type="file"]').setInputFiles(tmpFile);

  // Through clickOnceUploadSettles(), never a bare click: the button is
  // deliberately disabled for the duration of the upload, and clicking into
  // that transition is a race the browser resolves by dropping the click.
  await clickOnceUploadSettles(
    page,
    page.getByRole('button', { name: 'Upload', exact: true }),
    phase,
    'the Upload button',
  );

  await page
    .getByText(name, { exact: true })
    .waitFor({ timeout: 10000 })
    .catch(() => {});

  if (! fileRowExists(name)) {
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
    await page.getByLabel('Email address', { exact: true }).fill(email);
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
 *   - The version pill check is WEAK and is not a cross-source comparison.
 *     tinker reads config('doccum.version') and so does the Blade: same
 *     process, same source. The Dockerfile runs no config:cache, so the
 *     stale-cache scenario an earlier version of this comment described
 *     does not exist. What it does prove is narrow but real: the pill
 *     renders the configured value rather than a literal baked into the
 *     view. It becomes a genuine assertion once item/version-from-tag (36)
 *     gives an external source -- the image's
 *     org.opencontainers.image.version label -- to compare against.
 */
async function checkTopbar(page, phase) {
  const version = versionFromContainer();

  const pill = page.locator('[data-test="version-pill"]');
  await pill.waitFor({ state: 'visible', timeout: 10000 });
  const pillText = (await pill.innerText()).trim();
  if (pillText !== `v${version}`) {
    throw new Error(
      `version pill reads "${pillText}" but the running container reports ` +
        `config('doccum.version') = "${version}"`,
    );
  }
  console.log(`[${phase}] version pill renders the configured value: ${pillText}`);

  // The administrator holds properties.manage, so all three are expected.
  // A plain member seeing Settings is covered by the layout test; what is
  // asserted here is that the topbar renders at all outside the test
  // renderer, with Flux's own components resolving in the image.
  for (const nav of ['nav-home', 'nav-files', 'nav-settings']) {
    await page.locator(`[data-test="${nav}"]`).waitFor({ state: 'visible', timeout: 10000 });
  }
  console.log(`[${phase}] topbar shows Home, Files and Settings for the administrator`);

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

  return logout;
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
  await page.getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.locator('[data-test="upload-form"] input[type="file"]').waitFor({ state: 'attached', timeout: 10000 });
}

async function checkTrashRemovesFileFromListingAndSearch(page, phase) {
  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
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
  await page.getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.getByText(TRASH_CHECK_FILE_NAME, { exact: true }).waitFor({ timeout: 10000 });
  await page.getByText(TRASH_CHECK_FILE_NAME, { exact: true }).click();

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
 * whether or not Browser::replaceFile() actually threads the selected
 * file's name through to the action -- exactly the bug this item's mutation
 * (browser-replace-file's sibling in spirit, though that entry mutates the
 * authorize() call, not this line) is about: if replaceFile() ever passed
 * $this->replacement's OWN client name to StoreFileVersion instead of
 * $this->selectedFile->name, the call would go through the CREATE path --
 * a second File row named 'DoccumSmokeReplacement.txt' -- and only a
 * differing name makes that observable from the outside.
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
 */
async function checkReplaceAddsASecondVersion(page, phase) {
  const originalBody = 'Version 1 body, unique to the replace smoke check.\n';
  const replacementBody = 'Version 2 body, deliberately different from version 1.\n';
  const replacementFileName = 'DoccumSmokeReplacement.txt';

  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
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
  await page.getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.getByText(VERSIONS_CHECK_FILE_NAME, { exact: true }).waitFor({ timeout: 10000 });
  await page.getByText(VERSIONS_CHECK_FILE_NAME, { exact: true }).click();

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
  await replaceInput.setInputFiles(replacementPath);

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
  await page.getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.locator('[data-test="upload-form"] input[type="file"]').waitFor({ state: 'attached', timeout: 10000 });

  await uploadAndProveStored(page, name, body, phase);

  // Re-navigate before reading the row, for the reason recorded on
  // checkReplaceAddsASecondVersion(): straight after an upload the form's
  // file input still displays the chosen name, so the page carries it twice.
  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();

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
  await page.getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
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
  await page.getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
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
  await page.getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.getByText(survivorName, { exact: true }).waitFor({ timeout: 10000 });
  await page.getByText(victimOneName, { exact: true }).waitFor({ state: 'detached', timeout: 10000 });
  await page.getByText(victimTwoName, { exact: true }).waitFor({ state: 'detached', timeout: 10000 });
  console.log(`[${phase}] the listing shows only the survivor -- OK`);
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
    await Promise.all([
      // submit() ends in `redirect()->route('files.browse')` (see
      // FirstRun::submit) -- a real, full-page redirect, not a Livewire
      // ->navigate() morph, so a plain URL wait is enough.
      //
      // This wait is the whole of issue #97's evidence. Put the redirect back
      // to '/' and it times out here, because '/' is Route::view('/',
      // 'welcome') -- Laravel's starter page. The page.goto() below cannot
      // rescue it: this wait runs first.
      page.waitForURL((u) => u.pathname === '/files', { timeout: 15000 }),
      page.getByRole('button', { name: 'Create administrator account' }).click(),
    ]);
    console.log('[setup] installer complete, admin created and logged in');

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
    // true -- see config/doccum.php), so it is the one link on this page.
    await page.getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
    await page.locator('[data-test="upload-form"] input[type="file"]').waitFor({ state: 'attached', timeout: 10000 });

    console.log('[setup] stalling the upload endpoint to check the Upload button is disabled in flight (issue #106)');
    await checkUploadButtonIsDisabledWhileTheFileIsStillUploading(page, 'setup');

    console.log(`[setup] uploading ${FILE_NAME}`);
    await uploadAndProveStored(
      page,
      FILE_NAME,
      `This is a doccum container smoke test document containing the marker word ${FILE_MARKER}.\n`,
      'setup',
    );

    console.log(`[setup] waiting up to ${EXTRACTION_TIMEOUT_MS}ms for extraction to finish`);
    await waitForExtraction();
    console.log('[setup] extraction reached "done"');

    console.log('[setup] searching for the document by a word inside it');
    await page.goto(`${BASE_URL}/search`, { waitUntil: 'domcontentloaded' });
    await searchUntilFound(page, 'setup');
    console.log('[setup] search found the uploaded document -- OK');

    console.log('[setup] trashing a file through the detail panel and confirming it disappears from the listing and from search');
    await checkTrashRemovesFileFromListingAndSearch(page, 'setup');

    console.log('[setup] replacing a file through the detail panel and confirming a second version appears');
    await checkReplaceAddsASecondVersion(page, 'setup');

    console.log('[setup] following a Download link and checking the bytes come back (issue #74)');
    await checkDownloadReturnsTheUploadedBytes(page, 'setup');

    console.log('[setup] bulk-trashing two of three uploaded files and confirming the third survives');
    await checkBulkTrashLeavesUnselectedFilesAlone(page, 'setup');

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

    console.log('[verify] logging back in against the replacement container');
    await page.goto(`${BASE_URL}/login`, { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Email address', { exact: true }).fill(ADMIN_EMAIL);
    await page.getByLabel('Password', { exact: true }).fill(ADMIN_PASSWORD);
    await Promise.all([
      page.waitForURL((u) => u.pathname !== '/login', { timeout: 15000 }),
      page.getByRole('button', { name: 'Log in' }).click(),
    ]);
    console.log('[verify] logged in -- the admin account persisted');

    await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
    await page.getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
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
    await page.getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();

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
    } else {
      await runVerify();
    }
  } catch (error) {
    console.error(`\n::error::container-smoke (${phase}) failed: ${error.message}`);
    if (!error.dumped) dumpContainerState(`container-smoke (${phase}) failing state`);
    process.exit(1);
  }
})();

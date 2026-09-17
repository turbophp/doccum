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
 * Uploads a file through the browser and does not return until a files row
 * exists for it, retrying the interaction rather than the assertion.
 *
 * The retry is the point, and it is a diagnosis as much as a fix. Setting a
 * file on the input shortly after navigating into a directory sometimes
 * stores NOTHING -- no request, no error, no console output, and a listing
 * that still shows the name because the file input draws it. Reproduced in
 * two independent places: issue #98's first upload, and the trash target in
 * issue #108, both reported FILE:no. What separates them from the upload that
 * works is only how much happens between the navigation and setInputFiles.
 *
 * Waiting on window.Livewire was tried and proves nothing -- it is set when
 * the script first loads, so after a wire:navigate DOM swap it is already
 * true. Reproducing main's page sequence was tried too, and did not help.
 * Neither the cause nor a sound wait condition is known, so this retries the
 * whole interaction and SAYS which attempt worked: if attempt 2 routinely
 * succeeds the window is transient, and if no attempt ever does it is
 * structural. Either answer is worth more than another guess. Issue #106.
 *
 * Not a quarantine and not a skip: the assertion still has to pass, and the
 * run still fails loudly if no attempt stores the file.
 */
async function uploadAndProveStored(page, name, contents, phase) {
  const tmpFile = path.join(os.tmpdir(), name);
  fs.writeFileSync(tmpFile, contents);

  for (let attempt = 1; attempt <= 3; attempt += 1) {
    await page.locator('[data-test="upload-form"] input[type="file"]').setInputFiles(tmpFile);
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
    await page.getByRole('button', { name: 'Upload', exact: true }).click();
    await page
      .getByText(name, { exact: true })
      .waitFor({ timeout: 10000 })
      .catch(() => {});

    if (fileRowExists(name)) {
      console.log(`[${phase}] ${name} stored, confirmed by a files row (attempt ${attempt})`);
      return;
    }

    console.log(`[${phase}] attempt ${attempt} left no files row for ${name}`);
    await sleep(2000);
  }

  dumpContainerState(`${name} was uploaded three times and never produced a files row`);
  throw Object.assign(new Error(`no files row for ${name} after three upload attempts`), { dumped: true });
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
    "echo 'JOURNAL:' . $journal . ' BUSY:' . $busy;",
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

  console.log(`[setup] embedded database: journal_mode=${journal[1]}, busy_timeout=${busy[1]}ms`);
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
 * max(version_number) is asserted with >= 2, not === 2: issue #106's upload
 * race means a retried interaction can legitimately land a third version if
 * an earlier attempt silently landed one after all. The DOM count of
 * [data-test="file-version-row"] IS asserted at exactly 2, but subordinate
 * to the tinker checks above -- it is the only thing here proving the
 * version list actually renders in the shipped image (asset build, Flux
 * resolution), which is why CLAUDE.md wants a browser-driven check for a UI
 * item at all, but a mutation that broke replaceFile() itself while leaving
 * the (already-populated) version list rendering fine would sail through a
 * DOM-only assertion.
 *
 * TODO: mutated run URL (recorded by the orchestrator, see
 * checkTrashRemovesFileFromListingAndSearch()'s docblock for the shape of
 * that record).
 */
async function checkReplaceAddsASecondVersion(page, phase) {
  const originalBody = 'Version 1 body, unique to the replace smoke check.\n';
  const replacementBody = 'Version 2 body, deliberately different from version 1.\n';
  const replacementFileName = 'DoccumSmokeReplacement.txt';

  await page.goto(`${BASE_URL}/files`, { waitUntil: 'domcontentloaded' });
  await page.getByRole('link', { name: ADMIN_USERNAME, exact: true }).click();
  await page.locator('[data-test="upload-form"] input[type="file"]').waitFor({ state: 'attached', timeout: 10000 });

  await uploadAndProveStored(page, VERSIONS_CHECK_FILE_NAME, originalBody, phase);

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
  const replaceInput = page.locator('[data-test="replace-form"] input[type="file"]');
  await replaceInput.waitFor({ state: 'attached', timeout: 10000 });

  const replacementPath = path.join(os.tmpdir(), replacementFileName);
  fs.writeFileSync(replacementPath, replacementBody);
  await replaceInput.setInputFiles(replacementPath);

  await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
  await page.locator('[data-test="replace-file-button"]').click();

  // The version list is what render() re-queries on every update -- this is
  // the assertion that requires the feature to actually DO something
  // (CLAUDE.md), kept subordinate to the tinker checks below per this
  // function's own docblock.
  await page
    .locator('[data-test="file-version-row"]')
    .nth(1)
    .waitFor({ state: 'visible', timeout: 10000 });
  const rowCount = await page.locator('[data-test="file-version-row"]').count();
  if (rowCount !== 2) {
    dumpContainerState(`[${phase}] expected exactly 2 [data-test="file-version-row"] elements after replacing ${VERSIONS_CHECK_FILE_NAME}, found ${rowCount}`);
    throw Object.assign(new Error(`expected 2 version rows, found ${rowCount}`), { dumped: true });
  }
  console.log(`[${phase}] the version list rendered 2 rows after Replace -- the panel actually renders in this image`);

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

  const output = tinker(php);

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

  if (maxVersion < 2) {
    dumpContainerState(`[${phase}] ${VERSIONS_CHECK_FILE_NAME}'s max version_number is ${maxVersion}, expected at least 2`);
    throw Object.assign(new Error(`max version_number is ${maxVersion}, expected at least 2`), { dumped: true });
  }
  console.log(`[${phase}] ${VERSIONS_CHECK_FILE_NAME} has max(version_number) = ${maxVersion} (>= 2, per issue #106's retry note) -- OK`);
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

async function runSetup() {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();

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
    console.log('[verify] the uploaded file is still listed -- object storage persisted');

    await page.goto(`${BASE_URL}/search`, { waitUntil: 'domcontentloaded' });
    await searchUntilFound(page, 'verify');
    console.log('[verify] search still finds it -- the search index persisted -- OK');

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

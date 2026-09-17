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
//             a restart, then log out through the account menu. Run in a
//             fresh browser context (no cookies carried over), against the
//             SAME container after `docker restart`.
//
// All credentials and the marker text searched for come from the environment
// (set by the workflow step that invokes this), so both phases agree on them
// without this script persisting any state of its own between invocations.

import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
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
  return execFileSync(
    'docker',
    ['exec', CONTAINER_NAME, 'php', 'artisan', 'tinker', '--execute', php],
    { encoding: 'utf8', timeout: 20000 },
  );
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
 * Polls file_texts.status (via the File -> currentVersion -> text chain,
 * matching app/Models/File.php and app/Models/FileVersion.php) for the file
 * named FILE_NAME, bounded by EXTRACTION_TIMEOUT_MS. Never hangs: a status
 * that never reaches "done" -- including one that reaches "failed" or
 * "unsupported" -- fails loudly with container state rather than polling
 * forever.
 */
async function waitForExtraction() {
  const php = [
    `$f = \\App\\Models\\File::where('name', '${FILE_NAME}')->first();`,
    "if (!$f) { echo 'STATUS:NO_FILE'; exit; }",
    '$f->refresh();',
    '$v = $f->currentVersion;',
    "if (!$v) { echo 'STATUS:NO_VERSION'; exit; }",
    '$t = $v->text;',
    "echo 'STATUS:' . ($t ? $t->status->value : 'NO_TEXT');",
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
      dumpContainerState(
        `extraction reached a terminal non-success status (${last}) for ${FILE_NAME}`,
      );
      const err = new Error(`extraction status "${last}" for ${FILE_NAME}`);
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
      // submit() does `return redirect('/')` unconditionally on success (see
      // FirstRun::submit) -- a real, full-page redirect, not a Livewire
      // ->navigate() morph, so a plain URL wait is enough.
      page.waitForURL((u) => u.pathname === '/', { timeout: 15000 }),
      page.getByRole('button', { name: 'Create administrator account' }).click(),
    ]);
    console.log('[setup] installer complete, admin created and logged in');

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
    await page.locator('input[type="file"]').waitFor({ state: 'attached', timeout: 10000 });

    console.log(`[setup] uploading ${FILE_NAME}`);
    const tmpFile = path.join(os.tmpdir(), FILE_NAME);
    fs.writeFileSync(
      tmpFile,
      `This is a doccum container smoke test document containing the marker word ${FILE_MARKER}.\n`,
    );
    await page.locator('input[type="file"]').setInputFiles(tmpFile);
    // Livewire uploads the file to its temporary-upload endpoint as soon as
    // the input changes, asynchronously; give that request a moment to land
    // before submitting the form that references it.
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
    await page.getByRole('button', { name: 'Upload', exact: true }).click();
    await page.getByText(FILE_NAME, { exact: true }).waitFor({ timeout: 10000 });
    console.log('[setup] upload accepted, file listed in the directory');

    console.log(`[setup] waiting up to ${EXTRACTION_TIMEOUT_MS}ms for extraction to finish`);
    await waitForExtraction();
    console.log('[setup] extraction reached "done"');

    console.log('[setup] searching for the document by a word inside it');
    await page.goto(`${BASE_URL}/search`, { waitUntil: 'domcontentloaded' });
    await searchUntilFound(page, 'setup');
    console.log('[setup] search found the uploaded document -- OK');

    console.log('[setup] checking the password-reset URL honours a forwarded proto/host');
    checkForwardedPasswordResetUrl();
  } finally {
    await browser.close();
  }
}

async function runVerify() {
  const browser = await chromium.launch();
  try {
    // A brand new context: no cookies carried over from the setup phase, so
    // reaching the file genuinely proves the DATA survived the restart, not
    // just that this process kept a session alive.
    const context = await browser.newContext();
    const page = await context.newPage();

    console.log('[verify] logging back in after the restart');
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

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
//   setup  -- complete the installer, upload a file, wait for extraction,
//             confirm it is findable by search. Run once, against a freshly
//             booted, empty-volume container.
//   verify -- log in again and confirm the file and its search hit survived
//             a restart. Run in a fresh browser context (no cookies carried
//             over), against the SAME container after `docker restart`.
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
      execFileSync('docker', ['exec', CONTAINER_NAME, 'supervisorctl', 'status'], { encoding: 'utf8' }),
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
    await page.getByLabel('Search', { exact: true }).fill(FILE_MARKER);
    await page.getByText(FILE_NAME, { exact: true }).waitFor({ timeout: SEARCH_TIMEOUT_MS });
    console.log('[setup] search found the uploaded document -- OK');
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
    await page.getByLabel('Search', { exact: true }).fill(FILE_MARKER);
    await page.getByText(FILE_NAME, { exact: true }).waitFor({ timeout: SEARCH_TIMEOUT_MS });
    console.log('[verify] search still finds it -- the search index persisted -- OK');
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

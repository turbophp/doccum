<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Docs site generator audit -- issue #27 (item/docs-site), spec §12
|--------------------------------------------------------------------------
|
| item/docs-site's doneWhen is "The Pages workflow is green and the README
| links to the published site." The README link is deliberately NOT done
| here -- see this item's report -- so this file only covers the buildable
| half: a zero-dependency Node generator (.github/scripts/build-docs-site.mjs)
| that turns docs/self-hosting/*.md into a static site, and a Pages
| workflow (.github/workflows/pages.yml) whose build job runs it.
|
| This suite never shells out to `node`. The job that runs `php artisan
| test` (.github/workflows/tests.yml's "tests" job) has no Node setup step
| at all -- only a separate "assets" job installs Node, builds the Vite
| assets, and hands the compiled output to "tests" as a download-artifact --
| so a Pest test that ran `node build-docs-site.mjs` would pass locally
| and fail in CI for a reason that has nothing to do with the generator.
| CLAUDE.md's "no test may require an external binary" names tesseract as
| the example, but the same reasoning applies to node here: it is a binary
| this suite's own CI job does not have. So this test reads the
| generator's own source and checks its declared page list, the same way
| tests/Feature/SelfHostingDocsTest.php reads configuration-reference.md's
| source rather than parsing it through a Markdown engine.
*/

const DOCS_SITE_GENERATOR = '.github/scripts/build-docs-site.mjs';

const DOCS_SITE_WORKFLOW = '.github/workflows/pages.yml';

/**
 * Every self-hosting page spec §12 lists, per
 * tests/Feature/SelfHostingDocsTest.php's own DOC_AUDIT_PAGES, plus the
 * README that becomes the site's index. This is the independent source of
 * truth this test checks the generator's PAGES/INDEX_PAGE manifest
 * against -- not a copy of what the generator says, which would make this
 * test agree with itself no matter what the generator's list actually is.
 *
 * @var list<string>
 */
const EXPECTED_SELF_HOSTING_PAGES = [
    'README.md',
    'quick-start.md',
    'configuration-reference.md',
    'storage.md',
    'search.md',
    'operations-runbook.md',
    'backup-and-restore.md',
    'upgrading.md',
    'troubleshooting.md',
];

it('has a docs-site generator that declares every self-hosting page, and nothing else', function () {
    $path = base_path(DOCS_SITE_GENERATOR);

    expect(is_file($path))->toBeTrue('Missing generator: '.DOCS_SITE_GENERATOR);

    $source = file_get_contents($path);
    expect($source)->not->toBeFalse();

    // Pulls every `.md` filename the generator's PAGES/INDEX_PAGE manifest
    // names, from the source itself -- not by requiring or running the
    // script (see this file's own docblock for why not).
    preg_match_all("/file:\s*'([a-z0-9.\-]+\.md)'/i", $source, $matches);
    $declaredPages = $matches[1];

    expect($declaredPages)->not->toBeEmpty(
        'Found no `file: \'*.md\'` entries in '.DOCS_SITE_GENERATOR.
        ' -- either the manifest was renamed, or the regex above no longer matches it.',
    );

    sort($declaredPages);
    $expected = EXPECTED_SELF_HOSTING_PAGES;
    sort($expected);

    expect($declaredPages)->toBe(
        $expected,
        'build-docs-site.mjs declares '.implode(', ', $declaredPages).
        ' but docs/self-hosting/README.md lists (and spec §12 requires) '.
        implode(', ', $expected).
        '. Every self-hosting page must be reachable from the generated site, and nothing else should be.',
    );

    // Every filename it declares must actually exist under
    // docs/self-hosting/ -- catches a typo the string-comparison above
    // would happen to also catch here, but for the more direct reason
    // that a page in the manifest that isn't on disk breaks the build.
    foreach ($declaredPages as $file) {
        expect(is_file(base_path("docs/self-hosting/{$file}")))->toBeTrue(
            "build-docs-site.mjs declares {$file} but docs/self-hosting/{$file} does not exist.",
        );
    }
});

it('generator escapes HTML before emitting text or code, and protects code spans from later inline markup', function () {
    $source = file_get_contents(base_path(DOCS_SITE_GENERATOR));
    expect($source)->not->toBeFalse();

    // escapeHtml() must run on raw text before any markup is generated --
    // checked structurally (the function exists and replaces the five
    // characters that matter), since running the generator to prove this
    // behaviourally is exactly what this file's docblock explains this
    // suite cannot do in CI.
    expect($source)->toContain('function escapeHtml(');
    foreach (['&amp;', '&lt;', '&gt;', '&quot;', '&#39;'] as $entity) {
        expect($source)->toContain($entity);
    }

    // Fenced code content must go through escapeHtml with no inline
    // markup pass applied afterwards (renderInline, which turns `*`/`_`
    // into <strong>/<em>, must not run on code block content).
    expect($source)->toMatch('/case \'code\':.*?escapeHtml\(block\.content\)/s');
    expect($source)->not->toMatch('/case \'code\':.*?renderInline\(block\.content\)/s');

    // Code spans are pulled into placeholder tokens before bold/italic
    // processing, and only substituted back to real <code> tags afterwards
    // -- otherwise a code span containing a literal `*` (this codebase's
    // own docs have several, e.g. the `DB_*`/`AWS_*` env-var-prefix spans
    // in configuration-reference.md) would be misread as an italic marker
    // by the very next regex. Checked by requiring the codeSpans-array
    // pattern to be present.
    expect($source)->toContain('codeSpans.push(');
    expect($source)->toContain('CODE${codeSpans.length}');
});

it('has a Pages workflow with a build job that runs the generator and stays green with Pages disabled, and a deploy job gated to main', function () {
    $path = base_path(DOCS_SITE_WORKFLOW);

    expect(is_file($path))->toBeTrue('Missing workflow: '.DOCS_SITE_WORKFLOW);

    $workflow = file_get_contents($path);
    expect($workflow)->not->toBeFalse();

    // The build job must actually invoke the generator this test just
    // checked above, not some other script.
    expect($workflow)->toContain('node .github/scripts/build-docs-site.mjs');

    // Uploading a Pages artifact never requires the repository's Pages
    // setting to be turned on -- it is what makes the build job able to
    // stay green today. Only the deploy job (actions/deploy-pages) needs
    // that setting.
    expect($workflow)->toContain('actions/upload-pages-artifact@');
    expect($workflow)->toContain('actions/deploy-pages@');

    // Nothing may manufacture a green run by swallowing a real failure --
    // checked as an actual YAML key (`continue-on-error:`), not a bare
    // substring, because this workflow's own comments legitimately discuss
    // the concept in prose (explaining why the deploy job is allowed to
    // fail red rather than being made to look green).
    expect($workflow)->not->toMatch('/continue-on-error\s*:/');

    // The build job runs on push to main and on pull requests, so a
    // broken generator is caught before merge, not only after.
    expect($workflow)->toMatch('/on:\s*\n\s*push:\s*\n\s*branches:\s*\n\s*-\s*main/');
    expect($workflow)->toContain('pull_request:');

    // The deploy job depends on build and is restricted to main -- a
    // pull_request run must never attempt to deploy.
    expect($workflow)->toMatch('/deploy:.*?needs:\s*build/s');
    expect($workflow)->toMatch("/deploy:.*?if:\s*github\.ref == 'refs\/heads\/main'/s");

    // The standard permissions a Pages deployment needs, scoped to the
    // deploy job specifically (grep count rather than string position, so
    // this does not depend on the two jobs' declaration order).
    expect(substr_count($workflow, 'pages: write'))->toBe(1);
    expect(substr_count($workflow, 'id-token: write'))->toBe(1);

    // Node version comes from .nvmrc, not a second hardcoded copy --
    // CLAUDE.md's Node section is explicit that this project tracks one
    // Node version in one place.
    expect($workflow)->toContain("node-version-file: '.nvmrc'");
});

it('never touches package.json or package-lock.json', function () {
    // Zero-dependency by requirement (see build-docs-site.mjs's own
    // docblock): CLAUDE.md documents that npm 10 and npm 11 disagree
    // about this lockfile and rewrite it silently as a side effect of an
    // npm build/install command, so nothing about this item may add a
    // dependency or otherwise edit either file.
    //
    // Checked with comment lines stripped first, on both files: this
    // workflow's own comment explaining that constraint (and the
    // generator's own docblock, which explains the same thing by naming
    // the actual npm commands) would otherwise trip this same assertion
    // on the prose that documents it, not on an actual invocation.
    $stripYamlComments = fn (string $s): string => preg_replace('/^\s*#.*$/m', '', $s);
    $stripJsComments = function (string $s): string {
        $s = preg_replace('#/\*.*?\*/#s', '', $s); // /** ... */ blocks
        return preg_replace('#^\s*//.*$#m', '', $s); // // line comments
    };

    $workflow = file_get_contents(base_path(DOCS_SITE_WORKFLOW));
    expect($workflow)->not->toBeFalse();
    $workflowCode = $stripYamlComments($workflow);

    expect($workflowCode)->not->toContain('npm ci');
    expect($workflowCode)->not->toContain('npm install');
    expect($workflowCode)->not->toContain('npm run build');

    $generator = file_get_contents(base_path(DOCS_SITE_GENERATOR));
    expect($generator)->not->toBeFalse();
    $generatorCode = $stripJsComments($generator);

    expect($generatorCode)->not->toContain("from 'highlight.js'");
    expect($generatorCode)->not->toMatch('/^import\s.*\sfrom\s+\'(?!node:)[a-z]/mi'); // no bare (non-node:) package imports
});

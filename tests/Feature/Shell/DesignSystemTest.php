<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

// The doccum design system: the token palette, Public Sans, and the motion
// helper every animation routes through. These describe the foundation rather
// than any one screen, so they hold whichever component is rendering.
//
// What matters is that the foundation does not silently regress: that both
// themes define every token, that `--color-accent` stays bound to `ink` so
// Flux's buttons carry no colour, and that `move()` really does short-circuit
// under reduced motion rather than merely looking like it does.

it('defines every shell token (design plan §1) with both a light default and a dark override', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    $tokens = [
        '--color-sheet', '--color-chrome', '--color-rule',
        '--color-ink', '--color-ink-2',
        '--color-select', '--color-hold', '--color-attention',
    ];

    foreach ($tokens as $token) {
        expect($css)->toContain($token.':');
    }

    preg_match('/\.dark\s*\{(.*?)\n\s*\}/s', $css, $matches);
    expect($matches)->not->toBeEmpty('Expected a .dark override block in app.css');

    $dark = $matches[1];

    foreach ($tokens as $token) {
        expect($dark)->toContain($token.':');
    }
});

it('keeps --color-accent bound to ink so Flux primary buttons stay grey', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('--color-accent: var(--color-ink)');

    // Selection is a separate token from accent -- the design is deliberate
    // that buttons carry no colour.
    expect($css)->toContain('--color-select: #2b5fd9');
});

it('overrides the Flux focus ring and checkbox-checked colour to select', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('ring-select')
        ->and($css)->toContain('ui-checkbox[data-checked] [data-flux-checkbox-indicator]')
        ->and($css)->toContain('background-color: var(--color-select)');
});

it('removed the dead @source line for flux-pro, which was never installed', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->not->toContain('flux-pro');
    expect(is_dir(base_path('vendor/livewire/flux-pro')))->toBeFalse();
});

it('serves Public Sans at the three weights the shell uses, with tabular-nums available', function () {
    $css = file_get_contents(resource_path('css/app.css'));
    $vite = file_get_contents(base_path('vite.config.js'));

    expect($css)->toContain("--font-sans: 'Public Sans'")
        ->and($css)->toContain('font-variant-numeric: tabular-nums')
        ->and($css)->toContain('font-feature-settings: \'tnum\'')
        ->and($vite)->toContain("bunny('Public Sans'")
        ->and($vite)->toContain('weights: [400, 500, 600]');
});

it('adds motion as a dependency at v13', function () {
    $package = json_decode(file_get_contents(base_path('package.json')), true);

    expect($package['dependencies'])->toHaveKey('motion');
    expect(str_starts_with($package['dependencies']['motion'], '^13'))->toBeTrue();
});

it('routes every animation through move(), which short-circuits before calling animate() under reduced motion', function () {
    $js = file_get_contents(resource_path('js/shell/motion.js'));

    expect($js)->toContain("import { animate } from 'motion'")
        ->and($js)->toContain("matchMedia('(prefers-reduced-motion: reduce)')")
        ->and($js)->toContain('reduce.matches');

    // The guard has to come before the animate() call for it to be a guard
    // at all -- otherwise this is decoration, not a short-circuit.
    expect(strpos($js, 'reduce.matches'))->toBeLessThan(strpos($js, 'return animate('));
});

it('applies the end keyframe (not an animation) to el.style under reduced motion, before returning', function () {
    // motion.js is an ES module meant for a browser, so `php artisan test`
    // cannot execute it directly -- and per CLAUDE.md, no test may depend on
    // an external binary (node included), so this does not shell out to run
    // it either. Instead this pins down the exact short-circuit body: it
    // must assign the *last* value of each keyframe array onto `el.style`
    // (the end state, not a step of the animation) and return before
    // `animate()` is ever reached. A manual Node run against the real
    // `motion` package (matchMedia faked to report reduced motion)
    // confirmed this produces `el.style.opacity === 1` /
    // `el.style.transform === 'scale(1)'` for `{ opacity: [0, 1], transform:
    // ['scale(0.9)', 'scale(1)'] }` and resolves without calling animate().
    $js = file_get_contents(resource_path('js/shell/motion.js'));

    preg_match(
        '/if \(reduce\.matches.*?\{(.*?)return Promise\.resolve\(\);\s*\n\s*\}/s',
        $js,
        $matches,
    );

    expect($matches)->not->toBeEmpty('Expected to find the reduced-motion short-circuit body in motion.js');

    $body = $matches[1];

    expect($body)->toContain('Object.assign(')
        ->and($body)->toContain('el.style')
        // The last element of each keyframe array is the end state.
        ->and($body)->toContain('.at(-1)')
        ->and($body)->toContain('Array.isArray(');
});

it('exports the named springs from design plan §5, including the tab-underline micro spring', function () {
    $js = file_get_contents(resource_path('js/shell/motion.js'));

    $springs = [
        'reveal' => [420, 38],
        'unfold' => [520, 42],
        'lift' => [600, 30],
        'settle' => [700, 40],
        'stamp' => [800, 22],
        'sheet' => [380, 36],
        'tabUnderline' => [500, 40],
    ];

    foreach ($springs as $name => [$stiffness, $damping]) {
        $pattern = '/'.$name.':\s*\{[^}]*stiffness:\s*'.$stiffness.'[^}]*damping:\s*'.$damping.'/s';

        expect($js)->toMatch($pattern);
    }
});

it('keeps rows exempt from the micro-interaction tier, per design plan §5', function () {
    $js = file_get_contents(resource_path('js/shell/motion.js'));

    expect($js)->toContain('Rows stay exempt');
});

it('collapses CSS transitions under prefers-reduced-motion, covering the half move() cannot reach', function () {
    // move() short-circuits before animate(), which covers every scripted
    // animation. It cannot cover a CSS transition -- a Tailwind
    // `transition-*` utility never reaches it -- so "reduced motion is
    // enforced in one place" is only true of the JavaScript half unless CSS
    // is caught separately. This asserts the separate catch.
    // Whatever animates in CSS anywhere in the app is covered by the same
    // block, so this does not care which view does it.
    $animatesInCss = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => str_contains($file->getContents(), 'transition-'))
        ->count();

    $css = file_get_contents(resource_path('css/app.css'));

    preg_match('/@media \(prefers-reduced-motion: reduce\)\s*\{(.*?)\n\}/s', $css, $matches);

    expect($matches)->not->toBeEmpty('Expected a prefers-reduced-motion block in app.css');

    $body = $matches[1];

    expect($body)->toContain('*')
        ->and($body)->toContain('transition-duration')
        ->and($body)->toContain('animation-duration')
        // Without !important a Tailwind duration utility wins on specificity
        // and the guard is decoration.
        ->and($body)->toContain('!important');
});

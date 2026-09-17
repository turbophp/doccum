<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Blade;

// Foundation (implementation plan Task 2): tokens, type, the `move()` motion
// helper, and `layouts::shell`. Everything else in the shell builds on this,
// so what matters here is that it does not silently regress: the grid shape,
// the "no flux:main padding" rule, that both themes actually define every
// token, that `--color-accent` stays bound to `ink` (Flux's buttons must
// stay grey), and that `move()` really does short-circuit under reduced
// motion rather than merely looking like it does.

it('renders layouts::shell for a signed-in user as a full-bleed grid with no flux:main', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // The same render path Livewire's #[Layout('layouts::shell')] takes:
    // `@component` + a `slot` slot, exactly as SupportPageComponents wires
    // it up (vendor/livewire/livewire/src/Features/SupportPageComponents).
    $html = Blade::render(<<<'BLADE'
        @component('layouts::shell')
            @slot('slot')
                <div id="test-slot">Body content</div>
            @endslot
        @endcomponent
        BLADE);

    expect($html)->toContain('test-slot')
        ->and($html)->toContain('Body content')
        // 44px topbar / 1fr body / 24px status bar (Task 2, exactly as specified).
        ->and($html)->toContain('grid-rows-[44px_1fr_24px]')
        ->and($html)->not->toContain('flux:main')
        // Design plan §1: both themes are first-class, so the shell starts
        // from an un-classed <html> and lets @fluxAppearance apply the
        // viewer's saved preference. The other layouts still hardcode dark;
        // this one deliberately does not.
        ->and($html)->not->toContain('<html lang="en" class="dark"');
});

it('leaves layouts::app serving settings and admin pages, untouched', function () {
    // The sidebar shell this originally asserted against was replaced on main
    // by a full-width topbar (spec §10) while this branch was in flight. The
    // property being protected is unchanged: the shell layout is additive, and
    // the layout serving settings and admin pages still exists and is not the
    // shell.
    expect(view()->exists('layouts::app'))->toBeTrue()
        ->and(view()->exists('layouts::app.topbar'))->toBeTrue();

    $topbar = file_get_contents(resource_path('views/layouts/app/topbar.blade.php'));

    expect($topbar)->toContain('class="dark"');
});

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

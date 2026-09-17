<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    {{--
        No hardcoded `dark` class here: Flux's own appearance script
        (`@fluxAppearance`, in partials.head) decides the theme client-side,
        so both themes have to work from a cold, un-classed `<html>` -- the
        shell tokens in app.css are the thing that makes that safe (design
        plan §10).
    --}}
    <body class="h-dvh overflow-hidden bg-sheet text-ink antialiased">
        {{--
            Full-viewport CSS grid: 44px topbar / 1fr body / 24px status bar.
            No `flux:main` here -- that wraps content in Flux's page padding,
            which a full-bleed shell can't afford (design plan §3).

            The topbar and status bar are Task 8's; this task only lays out
            the grid they will occupy, guarded so the shell renders correctly
            before those files exist. The body row is the three panes
            (Tasks 3-5).
        --}}
        <div class="grid h-dvh grid-rows-[44px_1fr_24px]">
            <header class="border-b border-rule bg-chrome">
                {{--
                    `layouts.shell.topbar`, NOT `layouts.app.topbar`: main
                    already has a view at that path, and it is a complete HTML
                    document rather than a partial, so including it nested an
                    entire page inside this header. Namespacing the shell's own
                    partials keeps the two from colliding again.
                --}}
                @includeIf('layouts.shell.topbar')
            </header>

            <div class="min-h-0 overflow-hidden">
                {{ $slot }}
            </div>

            <footer class="border-t border-rule bg-chrome text-ink-2">
                @if (class_exists(\App\Livewire\Shell\StatusBar::class))
                    <livewire:shell.status-bar />
                @endif
            </footer>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>

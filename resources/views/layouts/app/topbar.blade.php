@props([
    // Opt-in, off by default: a page that manages its own full-viewport
    // layout (the file browser) asks for this, and every other page keeps
    // flux:main's padding and natural height. Passed from a component with
    // #[Layout('layouts::app', ['fullBleed' => true])].
    'fullBleed' => false,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="flex min-h-screen flex-col bg-white dark:bg-zinc-800">
        <flux:header class="sticky top-0 z-40 w-full max-w-none border-b border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <a
                href="{{ route('dashboard') }}"
                class="flex items-center gap-2 text-lg font-semibold lowercase tracking-tight text-zinc-900 dark:text-white"
                wire:navigate
            >
                doccum

                <flux:badge size="sm" color="zinc" data-test="version-pill">v{{ config('doccum.version') }}</flux:badge>
            </a>

            {{-- Instance-wide search, in the chrome rather than on a page of
                 its own, because looking something up is the most common
                 reason to be here at all. It submits a plain GET to the
                 search route, whose component reads `q` through #[Url], so it
                 works with JavaScript disabled and a result page can be
                 linked or bookmarked.

                 The accessible name is "Search documents", deliberately NOT
                 "Search": the search page's own field is labelled exactly
                 "Search", and the container smoke locates it page-wide with
                 getByLabel('Search', { exact: true }). A second element with
                 that exact name would make every one of those locators
                 ambiguous under Playwright's strict mode. --}}
            <div
                class="mx-6 hidden max-w-2xl flex-1 sm:block"
                x-data="{
                    hint: '⌘K',
                    init() {
                        // navigator.platform is deprecated but still the most
                        // reliable Mac signal; userAgent is the fallback.
                        const mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
                        this.hint = mac ? '⌘K' : 'Ctrl K';
                    },
                    focusSearch(event) {
                        if (! (event.metaKey || event.ctrlKey) || event.key.toLowerCase() !== 'k') {
                            return;
                        }

                        // Chrome and Firefox both bind ⌘K/Ctrl-K to the address
                        // bar, so this only works if the default is refused.
                        event.preventDefault();
                        this.$refs.q.focus();
                        this.$refs.q.select();
                    },
                }"
                x-on:keydown.window="focusSearch($event)"
            >
                <form method="GET" action="{{ route('search') }}" class="relative" data-test="header-search">
                    <flux:icon.magnifying-glass
                        variant="micro"
                        class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-zinc-400"
                        aria-hidden="true"
                    />

                    <input
                        x-ref="q"
                        type="search"
                        name="q"
                        value="{{ request()->routeIs('search') ? request()->query('q') : '' }}"
                        aria-label="{{ __('Search documents') }}"
                        placeholder="{{ __('Search documents') }}"
                        autocomplete="off"
                        class="h-8 w-full rounded-md border border-zinc-200 bg-white pl-8 pr-16 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-select focus:outline-none focus:ring-1 focus:ring-select dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                    />

                    <kbd
                        class="pointer-events-none absolute right-2 top-1/2 hidden -translate-y-1/2 rounded border border-zinc-200 bg-zinc-100 px-1.5 py-0.5 font-sans text-[11px] text-zinc-500 md:block dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400"
                        x-text="hint"
                        aria-hidden="true"
                    >⌘K</kbd>
                </form>
            </div>

            <flux:spacer />

            <flux:navbar class="-mb-px">
                <flux:navbar.item :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate data-test="nav-home">
                    {{ __('Home') }}
                </flux:navbar.item>

                <flux:navbar.item :href="route('files.browse')" :current="request()->routeIs('files.*')" wire:navigate data-test="nav-files">
                    {{ __('Files') }}
                </flux:navbar.item>

                @can('properties.manage')
                    <flux:navbar.item :href="route('admin.properties')" :current="request()->routeIs('admin.*')" wire:navigate data-test="nav-settings">
                        {{ __('Settings') }}
                    </flux:navbar.item>
                @endcan
            </flux:navbar>

            <flux:dropdown position="bottom" align="end">
                <span class="inline-flex items-center" data-test="account-menu-trigger">
                    <flux:tooltip content="{{ __('My Account') }}" position="bottom">
                        <flux:profile
                            :initials="auth()->user()->initials()"
                            icon-trailing="chevron-down"
                        />
                    </flux:tooltip>

                    <span class="sr-only">{{ __('My Account') }}</span>
                </span>

                <flux:menu>
                    <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                        <flux:avatar
                            :name="auth()->user()->name"
                            :initials="auth()->user()->initials()"
                        />

                        <div class="grid flex-1 text-start text-sm leading-tight">
                            <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                            <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                        </div>
                    </div>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="user" wire:navigate>
                            {{ __('Profile') }}
                        </flux:menu.item>

                        <flux:menu.item :href="route('security.edit')" icon="lock-closed" wire:navigate>
                            {{ __('Password & sessions') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        <flux:main @class([
            'w-full max-w-none flex-1 min-h-0',
            // flux:main ships p-6 lg:p-8; a full-bleed page draws its own
            // edges, so the padding is removed rather than fought with
            // negative margins.
            '!p-0' => $fullBleed,
        ])>
            {{ $slot }}
        </flux:main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>

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
        <flux:header class="sticky top-0 z-40 flex w-full max-w-none items-center gap-4 border-b border-zinc-200 bg-zinc-50 px-4 dark:border-zinc-700 dark:bg-zinc-900">
            {{-- Left zone: wordmark and primary nav, sized to content. --}}
            <div class="flex shrink-0 items-center gap-4">
            <a
                href="{{ route('dashboard') }}"
                class="flex items-center gap-2 text-lg font-semibold lowercase tracking-tight text-zinc-900 dark:text-white"
                wire:navigate
            >
                doccum

                <flux:badge size="sm" color="zinc" data-test="version-pill">v{{ config('doccum.version') }}</flux:badge>
            </a>

            <flux:navbar class="-mb-px">
                <flux:navbar.item :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate data-test="nav-home">
                    {{ __('Home') }}
                </flux:navbar.item>

                <flux:navbar.item :href="route('files.browse')" :current="request()->routeIs('files.*')" wire:navigate data-test="nav-files">
                    {{ __('Files') }}
                </flux:navbar.item>
            </flux:navbar>
            </div>

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
                class="hidden min-w-0 flex-1 justify-center sm:flex"
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
                <form method="GET" action="{{ route('search') }}" class="relative w-full max-w-xl" data-test="header-search">
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



            {{-- Right zone: sized to content, so the middle zone's growth is
                 what pushes it against the edge. --}}
            <div class="flex shrink-0 items-center gap-2">

            {{-- Notifications. The count is the only thing the bell says at
                 rest, and it says nothing at all when there is nothing unread:
                 a permanent "0" is a badge that trains people to ignore
                 badges. Its first producer is a finished directory archive --
                 zipping outlasts the tab that asked often enough that the
                 progress strip cannot be the only place it is reported. --}}
            @php
                $unread = auth()->user()->unreadNotifications()->latest()->take(5)->get();
            @endphp

            <flux:dropdown position="bottom" align="end">
                <span class="relative inline-flex items-center" data-test="notifications-trigger">
                    <flux:button variant="subtle" size="sm" icon="bell" :aria-label="__('Notifications')" />

                    @if ($unread->isNotEmpty())
                        <span
                            class="num pointer-events-none absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-select px-1 text-[10px] font-medium text-white"
                            data-test="notifications-count"
                        >{{ $unread->count() }}</span>
                    @endif
                </span>

                <flux:menu class="w-80">
                    @forelse ($unread as $notification)
                        <flux:menu.item
                            :href="$notification->data['url'] ?? route('files.browse')"
                            icon="archive-box-arrow-down"
                            data-test="notification-item"
                        >
                            {{ __(':name.zip is ready', ['name' => $notification->data['directory'] ?? __('Archive')]) }}
                        </flux:menu.item>
                    @empty
                        <div class="px-2 py-3 text-sm text-zinc-500" data-test="notifications-empty">
                            {{ __('Nothing new.') }}
                        </div>
                    @endforelse
                </flux:menu>
            </flux:dropdown>

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

                        {{--
                            Spec 10: Settings covers several sections (users and
                            role assignment, roles and permissions, property
                            definitions, archive periods, instance settings),
                            "Each section gated by its Spatie permission" -- so
                            an admin holding ONLY users.manage, and not
                            properties.manage, must still have a way in. Gating
                            the single entry on ONE section's permission hides
                            Settings from exactly that admin. properties.manage
                            is checked first only because it was the original
                            check's section; nothing depends on that order.
                        --}}
                        @can('properties.manage')
                            <flux:menu.item :href="route('admin.properties')" icon="cog-6-tooth" wire:navigate data-test="nav-settings">
                                {{ __('Settings') }}
                            </flux:menu.item>
                        @elsecan('users.manage')
                            {{-- Its own data-test, not "nav-settings", so a check
                                 logging in as a users.manage-only account can tell
                                 it reached THIS entry rather than merely that some
                                 Settings entry exists. --}}
                            <flux:menu.item :href="route('admin.users')" icon="cog-6-tooth" wire:navigate data-test="nav-settings-users">
                                {{ __('Settings') }}
                            </flux:menu.item>

                            {{-- item/admin-roles (issue #19): a second entry, not a
                                 second "Settings" -- two links with the identical
                                 accessible name "Settings" on one menu would make
                                 every locator by that name ambiguous under
                                 Playwright's strict mode (see the sidebar-home
                                 mutation entry in .github/mutations.json for the
                                 same lesson learned the hard way). Reuses
                                 users.manage rather than a new permission --
                                 App\Livewire\Admin\Roles's own docblock says why. --}}
                            <flux:menu.item :href="route('admin.roles')" icon="shield-check" wire:navigate data-test="nav-settings-roles">
                                {{ __('Roles') }}
                            </flux:menu.item>
                        @endcan
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
            </div>
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

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

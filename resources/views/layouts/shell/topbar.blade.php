{{--
    The shell's topbar (spec §10, design plan §3): type only -- a lowercase
    wordmark, the version pill, then Home / Files / Settings / account.

    The same markup main's `layouts.app.topbar` carries, lifted into a partial
    the full-bleed shell can include. That file is a complete HTML document,
    so the shell cannot include it directly: including it nested an entire
    page inside this header. Keeping one copy of the nav here means the shell
    and the rest of the app cannot drift apart in what they offer.

    Re-skinned to the shell tokens: 44px tall, on `chrome`, no zinc of its own.
--}}
<flux:header class="h-11 w-full max-w-none border-0 bg-transparent px-3">
            <a
                href="{{ route('dashboard') }}"
                class="flex items-center gap-2 text-[15px] font-semibold lowercase tracking-tight text-ink"
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

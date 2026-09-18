<section class="w-full space-y-6">
    <flux:heading level="1">{{ __('Users') }}</flux:heading>
    <flux:text>{{ __('Create accounts and assign roles. Every account gets its own home directory automatically.') }}</flux:text>

    @error('lastAdministrator')
        {{-- Plain <div>, not flux:callout -- see resources/views/livewire/trash/index.blade.php's
             own note: the Flux free tier this image ships does not include that
             component, and CLAUDE.md is explicit that a Blade assertion rendered
             with the test renderer cannot see a component Flux fails to resolve
             in the built image. This element is what the container smoke checks
             for after the last-admin refusal, so it has to actually render text
             in the real image, not merely under the test renderer. --}}
        <div data-test="last-admin-error" class="rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-700 dark:bg-red-950 dark:text-red-300">
            {{ $message }}
        </div>
    @enderror

    {{-- A plain list, not flux:table -- see resources/views/livewire/files/browser.blade.php's
         own note on why: flux:table does not exist in the free tier and fails to
         resolve in the built image while still rendering fine under the test renderer. --}}
    <div class="space-y-2" data-test="users-list">
        @foreach ($users as $user)
            <div class="flex items-center gap-4" wire:key="user-{{ $user->id }}" data-test="user-row" data-user-id="{{ $user->id }}">
                <div class="min-w-0 flex-1">
                    <flux:heading class="truncate">{{ $user->name }}</flux:heading>
                    <flux:text class="truncate text-sm">{{ $user->username }} &mdash; {{ $user->email }}</flux:text>
                </div>

                <flux:select wire:model="roleChoice.{{ $user->id }}" data-test="role-select">
                    <flux:select.option value="">{{ __('(no role)') }}</flux:select.option>
                    @foreach ($roles as $availableRole)
                        <flux:select.option :value="$availableRole->name">{{ $availableRole->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:button wire:click="changeRole({{ $user->id }})" data-test="change-role-button">
                    {{ __('Update role') }}
                </flux:button>
            </div>
        @endforeach
    </div>

    <form wire:submit="save" class="max-w-lg space-y-4" data-test="create-user-form">
        <flux:heading level="2">{{ __('New user') }}</flux:heading>

        <flux:input wire:model="name" :label="__('Name')" type="text" />
        <flux:input wire:model="username" :label="__('Username')" type="text" />
        <flux:input wire:model="email" :label="__('Email address')" type="email" />
        <flux:input wire:model="password" :label="__('Password')" type="password" />
        <flux:input wire:model="password_confirmation" :label="__('Confirm password')" type="password" />

        <flux:select wire:model="role" :label="__('Role')">
            <flux:select.option value="">{{ __('(no role)') }}</flux:select.option>
            @foreach ($roles as $availableRole)
                <flux:select.option :value="$availableRole->name">{{ $availableRole->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:button type="submit" variant="primary" data-test="create-user-button">{{ __('Create user') }}</flux:button>
    </form>
</section>

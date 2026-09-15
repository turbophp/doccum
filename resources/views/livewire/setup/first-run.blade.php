<div class="flex flex-col gap-6">
    <x-auth-header :title="__('Set up doccum')" :description="__('Create the first administrator account for this instance')" />

    <form wire:submit="submit" class="flex flex-col gap-6">
        <!-- Instance name -->
        <flux:input
            wire:model="instance_name"
            :label="__('Instance name')"
            type="text"
            required
            autofocus
            :placeholder="__('doccum')"
        />

        <!-- Name -->
        <flux:input
            wire:model="name"
            :label="__('Name')"
            type="text"
            required
            autocomplete="name"
            :placeholder="__('Full name')"
        />

        <!-- Username -->
        <flux:input
            wire:model="username"
            :label="__('Username')"
            type="text"
            required
            autocomplete="username"
            :placeholder="__('username')"
            :description="__('Lowercase letters, numbers, dots, dashes and underscores. Names your personal folder.')"
        />

        <!-- Email Address -->
        <flux:input
            wire:model="email"
            :label="__('Email address')"
            type="email"
            required
            autocomplete="email"
            placeholder="email@example.com"
        />

        <!-- Password -->
        <flux:input
            wire:model="password"
            :label="__('Password')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('Password')"
            passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
            viewable
        />

        <!-- Confirm Password -->
        <flux:input
            wire:model="password_confirmation"
            :label="__('Confirm password')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('Confirm password')"
            passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
            viewable
        />

        <div class="flex items-center justify-end">
            <flux:button type="submit" variant="primary" class="w-full" data-test="setup-submit-button">
                {{ __('Create administrator account') }}
            </flux:button>
        </div>
    </form>
</div>

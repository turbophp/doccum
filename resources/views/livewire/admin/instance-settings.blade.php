<section class="w-full max-w-2xl space-y-8" data-test="instance-settings-page">
    <div>
        <flux:heading level="1">{{ __('Instance settings') }}</flux:heading>
        <flux:text>{{ __('Instance-wide configuration: name, public sign-up, and document retention.') }}</flux:text>
    </div>

    <form wire:submit="saveInstanceName" class="max-w-lg space-y-4" data-test="instance-name-form">
        <flux:heading level="2">{{ __('Instance name') }}</flux:heading>

        @error('instanceName')
            {{-- Plain <div>, not flux:callout -- see resources/views/livewire/admin/periods.blade.php's
                 own note: the Flux free tier this image ships does not include
                 flux:callout, and a Blade assertion rendered with the test
                 renderer cannot see a component Flux fails to resolve in the
                 built image. --}}
            <div data-test="instance-name-error" class="rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-700 dark:bg-red-950 dark:text-red-300">
                {{ $message }}
            </div>
        @enderror

        {{-- No data-test on the input itself, deliberately -- see
             resources/views/livewire/admin/periods.blade.php's own note:
             flux:input renders a label/wrapper around the real <input>,
             and there is no precedent in this codebase for an attribute
             placed on flux:input landing on that inner element in the built
             image. getByLabel() locates it instead. --}}
        <flux:input wire:model="instanceName" :label="__('Instance name')" type="text" />

        <flux:button type="submit" variant="primary" data-test="instance-name-save-button">{{ __('Save') }}</flux:button>
    </form>

    <form wire:submit="saveSignup" class="max-w-lg space-y-4" data-test="instance-signup-form">
        <flux:heading level="2">{{ __('Public sign-up') }}</flux:heading>
        <flux:text>{{ __('Off by default. When off, /register answers 404 instead of showing a form nobody should be able to reach.') }}</flux:text>

        {{-- No data-test -- flux:checkbox is only known to forward
             arbitrary attributes the same way flux:input does not (see the
             note above); getByLabel() drives it instead. --}}
        <flux:checkbox wire:model="publicSignup" :label="__('Allow anyone to create an account')" />

        <flux:button type="submit" variant="primary" data-test="instance-signup-save-button">{{ __('Save') }}</flux:button>
    </form>

    <form wire:submit="saveRetention" class="max-w-lg space-y-4" data-test="instance-retention-form">
        <flux:heading level="2">{{ __('Retention') }}</flux:heading>
        <flux:text>{{ __('How long an archived period is kept before it may be purged. See the Archive periods page to close and purge periods.') }}</flux:text>

        @error('retention')
            <div data-test="instance-retention-error" class="rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-700 dark:bg-red-950 dark:text-red-300">
                {{ $message }}
            </div>
        @enderror

        <flux:input
            wire:model="retentionPurgeAfterYears"
            :label="__('Purge archived periods after this many years (leave blank for no retention window)')"
            type="text"
            inputmode="numeric"
        />

        <flux:checkbox wire:model="autoPurge" :label="__('Automatically purge periods once they age past the retention window')" />

        <flux:button type="submit" variant="primary" data-test="instance-retention-save-button">{{ __('Save') }}</flux:button>
    </form>
</section>

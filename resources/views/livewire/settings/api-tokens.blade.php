<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('API tokens') }}</flux:heading>

    <x-settings.layout :heading="__('API tokens')" :subheading="__('Create and revoke personal access tokens for the API')">
        @if ($plainTextToken)
            {{-- Plain <div>, not flux:callout -- see resources/views/livewire/admin/roles.blade.php's
                 own note: the Flux free tier this image ships does not include
                 flux:callout, and a Blade assertion rendered with the test
                 renderer cannot see a component Flux fails to resolve in the
                 built image. This is the ONE place the plaintext token is ever
                 printed: it comes from $plainTextToken, a component property that
                 mount() never repopulates (see App\Livewire\Settings\ApiTokens's
                 own docblock), so it exists here only for the render that
                 immediately follows creating the token. --}}
            <div data-test="new-token-callout" class="mt-6 space-y-2 rounded border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                <p class="font-medium">{{ __('Copy this token now. It will not be shown again.') }}</p>
                <code data-test="new-token-value" class="block break-all rounded bg-white/60 px-2 py-1 font-mono text-xs dark:bg-black/30">{{ $plainTextToken }}</code>
            </div>
        @endif

        <form wire:submit="createToken" class="mt-6 space-y-6" data-test="create-token-form">
            <flux:input wire:model="name" :label="__('Name')" type="text" required />

            <div data-test="ability-checkboxes">
                {{-- flux:label has no established precedent elsewhere in this
                     codebase's Blade -- flux:text is used everywhere else for a
                     standalone label, so it is the safer choice here too. --}}
                <flux:text class="font-medium">{{ __('Abilities') }}</flux:text>

                <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach ($availableAbilities as $ability)
                        {{-- A plain <input type="checkbox">, not flux:checkbox -- the
                             same choice App\Livewire\Admin\Roles's matrix makes and for
                             the same reason: this binds several checkboxes onto ONE
                             array property (selectedAbilities) by giving each the
                             ability's value, and Flux is only known to forward
                             arbitrary attributes on flux:button. --}}
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                wire:model="selectedAbilities"
                                value="{{ $ability->value }}"
                                data-test="ability-checkbox"
                                data-ability="{{ $ability->value }}"
                                class="rounded border-zinc-300 dark:border-zinc-600"
                            />
                            <span>{{ $ability->label() }}</span>
                        </label>
                    @endforeach
                </div>
                @error('selectedAbilities')
                    <flux:text class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror
                @error('selectedAbilities.*')
                    <flux:text class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror
            </div>

            <flux:input wire:model="expiresAt" :label="__('Expires')" type="date" :description="__('Leave blank for a token that never expires')" />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="create-token-button">{{ __('Create token') }}</flux:button>
            </div>
        </form>

        <section class="mt-12">
            <flux:heading>{{ __('Your tokens') }}</flux:heading>
            <flux:subheading>{{ __('Revoking a token takes effect immediately') }}</flux:subheading>

            {{-- A plain list, not flux:table -- see resources/views/livewire/admin/users.blade.php's
                 own note on why: flux:table does not exist in the free tier and fails
                 to resolve in the built image while still rendering fine under the
                 test renderer. --}}
            <div class="mt-6 space-y-2" data-test="tokens-list">
                @forelse ($tokens as $token)
                    <div class="flex items-center justify-between gap-4 rounded border border-zinc-200 p-4 dark:border-zinc-700" wire:key="token-{{ $token['id'] }}" data-test="token-row" data-token-id="{{ $token['id'] }}">
                        <div class="min-w-0 flex-1 space-y-1">
                            <flux:heading class="truncate">{{ $token['name'] }}</flux:heading>

                            <div class="flex flex-wrap gap-1">
                                @foreach ($token['abilities'] as $ability)
                                    <flux:badge size="sm">{{ $ability }}</flux:badge>
                                @endforeach
                            </div>

                            <flux:text class="text-xs">
                                {{ __('Created :time', ['time' => $token['created_at_diff']]) }}
                                <span class="opacity-50 mx-1">/</span>
                                @if ($token['last_used_at_diff'])
                                    {{ __('Last used :time', ['time' => $token['last_used_at_diff']]) }}
                                @else
                                    {{ __('Never used') }}
                                @endif
                                @if ($token['expires_at_diff'])
                                    <span class="opacity-50 mx-1">/</span>
                                    {{ __('Expires :time', ['time' => $token['expires_at_diff']]) }}
                                @endif
                            </flux:text>
                        </div>

                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="trash"
                            icon:variant="outline"
                            wire:click="confirmRevoke({{ $token['id'] }})"
                            data-test="revoke-token-button"
                            class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                        />
                    </div>
                @empty
                    <div class="p-8 text-center" data-test="no-tokens">
                        <p class="font-medium">{{ __('No tokens yet') }}</p>
                        <flux:text class="mt-1">{{ __('Create a token above to use the API') }}</flux:text>
                    </div>
                @endforelse
            </div>
        </section>
    </x-settings.layout>

    <flux:modal
        name="revoke-token-modal"
        class="max-w-md md:min-w-md"
        @close="closeRevokeModal"
        wire:model="showRevokeModal"
    >
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Revoke token') }}</flux:heading>
                <flux:text>
                    {{ __('Are you sure you want to revoke the token ":name"? Anything using it will immediately lose access.', ['name' => $revokingTokenName]) }}
                </flux:text>
            </div>

            <div class="flex gap-3 justify-end">
                <flux:button
                    variant="outline"
                    wire:click="closeRevokeModal"
                >
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    variant="danger"
                    wire:click="revokeToken"
                    data-test="confirm-revoke-button"
                >
                    {{ __('Revoke token') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>

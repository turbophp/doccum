<section class="w-full space-y-6">
    <flux:heading level="1">{{ __('Property definitions') }}</flux:heading>
    <flux:text>{{ __('Admin-defined metadata fields available on directories and files.') }}</flux:text>

    <div class="space-y-2">
        @forelse ($definitions as $definition)
            <div class="flex items-center gap-4">
                <flux:badge>{{ $definition->key }}</flux:badge>
                <span>{{ $definition->label }}</span>
                <flux:text class="text-sm">{{ $definition->data_type->label() }}</flux:text>
                <flux:text class="text-sm">{{ $definition->applies_to->value }}</flux:text>
            </div>
        @empty
            <flux:text>{{ __('No property definitions yet.') }}</flux:text>
        @endforelse
    </div>

    <form wire:submit="save" class="max-w-lg space-y-4">
        <flux:heading level="2">{{ __('New definition') }}</flux:heading>

        <flux:input wire:model="key" :label="__('Key')" type="text" />
        <flux:input wire:model="label" :label="__('Label')" type="text" />

        <flux:select wire:model="data_type" :label="__('Data type')">
            @foreach (App\Enums\PropertyDataType::cases() as $type)
                <flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($data_type === App\Enums\PropertyDataType::Select->value)
            <flux:textarea wire:model="options_text" :label="__('Options (one per line)')" />
        @endif

        <flux:select wire:model="applies_to" :label="__('Applies to')">
            @foreach (App\Enums\AppliesTo::cases() as $target)
                <flux:select.option :value="$target->value">{{ $target->value }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:checkbox wire:model="is_required" :label="__('Required')" />

        <flux:button type="submit" variant="primary">{{ __('Add definition') }}</flux:button>
    </form>
</section>

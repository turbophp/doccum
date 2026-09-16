<section class="w-full space-y-4">
    <flux:heading level="2">{{ __('Properties') }}</flux:heading>

    <form wire:submit="save" class="space-y-4">
        @forelse ($definitions as $definition)
            @php $field = "values.{$definition->key}"; @endphp

            @if ($definition->data_type === \App\Enums\PropertyDataType::Boolean)
                <flux:checkbox wire:model="{{ $field }}" :label="$definition->label" />
            @elseif ($definition->data_type === \App\Enums\PropertyDataType::Select)
                <flux:select wire:model="{{ $field }}" :label="$definition->label">
                    <flux:select.option value="">{{ __('-- none --') }}</flux:select.option>
                    @foreach ($definition->options ?? [] as $option)
                        <flux:select.option :value="$option">{{ $option }}</flux:select.option>
                    @endforeach
                </flux:select>
            @elseif ($definition->data_type === \App\Enums\PropertyDataType::Text)
                <flux:textarea wire:model="{{ $field }}" :label="$definition->label" />
            @elseif ($definition->data_type === \App\Enums\PropertyDataType::Date)
                <flux:input wire:model="{{ $field }}" :label="$definition->label" type="date" />
            @elseif ($definition->data_type === \App\Enums\PropertyDataType::Number)
                <flux:input wire:model="{{ $field }}" :label="$definition->label" type="number" step="any" />
            @else
                <flux:input wire:model="{{ $field }}" :label="$definition->label" type="text" />
            @endif
        @empty
            <flux:text>{{ __('No properties apply here.') }}</flux:text>
        @endforelse

        @if ($definitions->isNotEmpty())
            <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
        @endif
    </form>
</section>

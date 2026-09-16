<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Enums\AppliesTo;
use App\Enums\PropertyDataType;
use App\Models\PropertyDefinition;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Definition CRUD, gated entirely by the `properties.manage` permission.
 *
 * A key typed by an operator is normalised to `[a-z0-9_]` rather than
 * rejected, because the admin picking the key is naming the field for
 * themselves -- there is no external system depending on their exact
 * keystrokes the way there would be for, say, a URL slug shared elsewhere.
 */
#[Layout('layouts::app')]
class PropertyDefinitions extends Component
{
    public string $key = '';

    public string $label = '';

    public string $data_type = 'string';

    public string $options_text = '';

    public bool $is_required = false;

    public string $applies_to = 'both';

    public int $sort_order = 0;

    public function mount(): void
    {
        $this->authorize('viewAny', PropertyDefinition::class);
    }

    public function save(): void
    {
        $this->authorize('create', PropertyDefinition::class);

        $this->key = Str::slug($this->key, '_');

        $validated = $this->validate([
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/', Rule::unique('property_definitions', 'key')],
            'label' => ['required', 'string', 'max:191'],
            'data_type' => ['required', Rule::enum(PropertyDataType::class)],
            'options_text' => ['required_if:data_type,select'],
            'applies_to' => ['required', Rule::enum(AppliesTo::class)],
            'sort_order' => ['integer', 'min:0'],
        ]);

        PropertyDefinition::create([
            'key' => $validated['key'],
            'label' => $validated['label'],
            'data_type' => $validated['data_type'],
            'options' => $this->data_type === PropertyDataType::Select->value ? $this->parsedOptions() : null,
            'is_required' => $this->is_required,
            'applies_to' => $validated['applies_to'],
            'sort_order' => $validated['sort_order'],
        ]);

        $this->reset(['key', 'label', 'data_type', 'options_text', 'is_required', 'applies_to', 'sort_order']);
    }

    /**
     * One option per line, blank lines dropped.
     *
     * @return array<int, string>
     */
    private function parsedOptions(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $this->options_text))));
    }

    public function render(): View
    {
        return view('livewire.admin.property-definitions', [
            'definitions' => PropertyDefinition::query()->ordered()->get(),
        ]);
    }
}

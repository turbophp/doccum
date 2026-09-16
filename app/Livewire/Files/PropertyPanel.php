<?php

declare(strict_types=1);

namespace App\Livewire\Files;

use App\Actions\Properties\SetProperties;
use App\Models\Directory;
use App\Models\File;
use App\Models\Property;
use App\Models\PropertyDefinition;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Editing the property values of one directory or file.
 *
 * Viewing requires `view` on the subject; saving requires `update`. Neither
 * check lives in SetProperties -- that action assumes the caller has already
 * decided, and this component is the caller.
 */
class PropertyPanel extends Component
{
    public Directory|File $subject;

    /** @var array<string, mixed> */
    public array $values = [];

    public function mount(Directory|File $subject): void
    {
        $this->authorize('view', $subject);

        $this->subject = $subject;

        foreach ($this->definitions() as $definition) {
            $this->values[$definition->key] = Property::for($subject, $definition)->value ?? '';
        }
    }

    public function save(SetProperties $action): void
    {
        $this->authorize('update', $this->subject);

        try {
            $action->handle($this->subject, $this->values);
        } catch (ValidationException $e) {
            $this->setErrorBag(
                collect($e->errors())
                    ->mapWithKeys(fn (array $messages, string $key): array => ["values.{$key}" => $messages])
                    ->all()
            );
        }
    }

    /** @return Collection<int, PropertyDefinition> */
    private function definitions(): Collection
    {
        return PropertyDefinition::query()
            ->for($this->subject->getMorphClass())
            ->ordered()
            ->get();
    }

    public function render(): View
    {
        return view('livewire.files.property-panel', [
            'definitions' => $this->definitions(),
        ]);
    }
}

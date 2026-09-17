<?php

declare(strict_types=1);

namespace App\Livewire\Files;

use App\Actions\Properties\SetProperties;
use App\Models\Directory;
use App\Models\File;
use App\Models\FileText;
use App\Models\FileVersion;
use App\Models\Property;
use App\Models\PropertyDefinition;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The detail pane: one flat pane for a directory or a file, tabbed into
 * Properties / Versions / Text with hairlines between sections rather than
 * stacked cards (design plan §7 -- the SaaS-card kit is explicitly rejected).
 *
 * Properties' authorisation and value-seeding are LIFTED from PropertyPanel
 * rather than composed: PropertyPanel owns its own markup (a card with a
 * heading and vertical spacing) and this pane's markup is deliberately
 * different (flat sections, tabs, a hold band). Composing PropertyPanel as a
 * nested Livewire component would mean rendering its markup as-is -- the
 * thing this task exists to replace -- and PropertyPanel.php/its view are
 * both outside this task's owned files, so they cannot be changed to fit.
 * Lifting keeps the guarantees (view to see, update to save, the same
 * ValidationException -> values.{key} mapping) with none of PropertyPanel's
 * markup.
 */
class Detail extends Component
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

    /**
     * Every version of this file, newest first -- or null for a directory,
     * which has no versions at all (not an empty list: the tab itself is
     * absent, design plan Task 5).
     *
     * @return Collection<int, FileVersion>|null
     */
    private function versions(): ?Collection
    {
        $subject = $this->subject;

        if (! $subject instanceof File) {
            return null;
        }

        // Queried directly against FileVersion rather than through
        // File::versions() -- that relation has no `@return HasMany<...>`
        // generic (unlike currentVersion()), which is File.php's gap, not
        // this component's to fix (outside this task's owned files).
        // Querying the typed model directly keeps this method's own return
        // type honest without touching it.
        return FileVersion::query()
            ->where('file_id', $subject->getKey())
            ->orderByDesc('version_number')
            ->get();
    }

    /** The extracted text row for the current version, or null for a directory or a version with none yet. */
    private function currentText(): ?FileText
    {
        $subject = $this->subject;

        if (! $subject instanceof File) {
            return null;
        }

        return $subject->currentVersion?->text;
    }

    public function render(): View
    {
        return view('livewire.files.detail', [
            'definitions' => $this->definitions(),
            'versions' => $this->versions(),
            'currentVersionId' => $this->subject instanceof File ? $this->subject->current_version_id : null,
            'fileText' => $this->currentText(),
        ]);
    }
}

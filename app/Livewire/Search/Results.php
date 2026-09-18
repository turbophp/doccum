<?php

declare(strict_types=1);

namespace App\Livewire\Search;

use App\Models\Directory;
use App\Models\PropertyDefinition;
use App\Services\Search;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts::app')]
class Results extends Component
{
    /** Kept in the URL so a search can be shared, bookmarked and reloaded. */
    #[Url(as: 'q', except: '')]
    public string $query = '';

    #[Url(as: 'type', except: '')]
    public string $subjectType = '';

    /** Exact match against `search_documents.mime` -- see spec §10. */
    #[Url(as: 'mime', except: '')]
    public string $mime = '';

    #[Url(as: 'year', except: '')]
    public string $periodYear = '';

    #[Url(as: 'month', except: '')]
    public string $periodMonth = '';

    #[Url(as: 'property', except: '')]
    public string $propertyDefinitionId = '';

    #[Url(as: 'value', except: '')]
    public string $propertyValue = '';

    /**
     * A definition's value column, and so its meaning, can change under a
     * stale value: switching from a Yes/No definition to a text one and
     * keeping "true" typed in the value field would filter on a value the
     * new type never produces. Clearing it is the same "empty means absent"
     * rule PropertyDataType::cast() already applies when a property is
     * written.
     */
    public function updatedPropertyDefinitionId(): void
    {
        $this->propertyValue = '';
    }

    public function render()
    {
        $hits = trim($this->query) === ''
            ? new Collection
            : app(Search::class)->for(auth()->user(), $this->query, $this->filters());

        // Names for the directories the hits live in, fetched once rather than
        // per row. These are only hits the viewer may already reach, so no
        // additional access check is needed here.
        $directories = Directory::query()
            ->whereIn('id', $hits->pluck('directoryId')->filter()->unique())
            ->pluck('name', 'id');

        return view('livewire.search.results', [
            'hits' => $hits,
            'directories' => $directories,
            'definitions' => PropertyDefinition::query()->ordered()->get(),
            'selectedDefinition' => $this->propertyDefinitionId !== ''
                ? PropertyDefinition::find($this->propertyDefinitionId)
                : null,
        ]);
    }

    /**
     * Every filter the viewer chose, applied inside Search::for()'s own
     * query alongside the permission filter it resolves -- never as a
     * second pass over what came back. See Services\Search's docblock.
     *
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        $filters = [];

        if ($this->subjectType !== '') {
            $filters['subject_type'] = $this->subjectType;
        }

        if (trim($this->mime) !== '') {
            $filters['mime'] = trim($this->mime);
        }

        if ($this->periodYear !== '' && ctype_digit($this->periodYear)) {
            $filters['period_year'] = (int) $this->periodYear;
        }

        if ($this->periodMonth !== '' && ctype_digit($this->periodMonth)) {
            $filters['period_month'] = (int) $this->periodMonth;
        }

        if ($this->propertyDefinitionId !== '' && ctype_digit($this->propertyDefinitionId) && $this->propertyValue !== '') {
            $filters['property_definition_id'] = (int) $this->propertyDefinitionId;
            $filters['property_value'] = $this->propertyValue;
        }

        return $filters;
    }
}

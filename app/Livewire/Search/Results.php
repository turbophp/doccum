<?php

declare(strict_types=1);

namespace App\Livewire\Search;

use App\Models\Directory;
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

    public function render()
    {
        $hits = trim($this->query) === ''
            ? new Collection
            : app(Search::class)->for(
                auth()->user(),
                $this->query,
                $this->subjectType !== '' ? ['subject_type' => $this->subjectType] : [],
            );

        // Names for the directories the hits live in, fetched once rather than
        // per row. These are only hits the viewer may already reach, so no
        // additional access check is needed here.
        $directories = Directory::query()
            ->whereIn('id', $hits->pluck('directoryId')->filter()->unique())
            ->pluck('name', 'id');

        return view('livewire.search.results', [
            'hits' => $hits,
            'directories' => $directories,
        ]);
    }
}

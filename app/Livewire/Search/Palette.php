<?php

declare(strict_types=1);

namespace App\Livewire\Search;

use App\Models\Directory;
use App\Models\File;
use App\Search\SearchHit;
use App\Services\Search;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Instance-wide search as a command palette.
 *
 * Search used to be a page you navigated to, typed into, and read a list on.
 * That is three deliberate steps for the thing people do most often, and it
 * loses your place in the file browser to do it. The palette answers the same
 * question over the top of whatever you were looking at, and opening a result
 * puts you in front of the document rather than in front of another list.
 *
 * It does NOT re-implement searching. Every query goes through Services\Search,
 * which resolves the viewer's reach per query so a revoked grant takes effect
 * on the next keystroke rather than the next reindex -- the seam exists so that
 * no caller can forget, and a second search surface is exactly the caller that
 * would.
 */
class Palette extends Component
{
    /** How many hits the palette shows. It is a shortcut, not the results page. */
    private const LIMIT = 8;

    public bool $open = false;

    public string $query = '';

    /**
     * Opened from the topbar's field or by the keyboard shortcut.
     */
    public function openPalette(): void
    {
        $this->open = true;
    }

    public function closePalette(): void
    {
        $this->open = false;
        $this->query = '';
    }

    /**
     * @return Collection<int, SearchHit>
     */
    public function hits(): Collection
    {
        $query = trim($this->query);

        if (! $this->open || mb_strlen($query) < 2) {
            return new Collection;
        }

        return app(Search::class)->for(auth()->user(), $query, [], self::LIMIT);
    }

    /**
     * Where a hit goes when it is chosen.
     *
     * A file opens in its own preview -- the whole reason the preview took a
     * URL -- and a directory opens as a listing. Resolved here rather than in
     * the view so an unreachable hit cannot produce a link at all; the hits
     * themselves already come back scoped by Services\Search.
     */
    public function destinationFor(SearchHit $hit): ?string
    {
        if ($hit->subjectType === 'file') {
            $file = File::query()->find($hit->subjectId);

            return $file === null
                ? null
                : route('files.browse', ['directory' => $file->directory_id, 'file' => $file->getKey()]);
        }

        $directory = Directory::query()->find($hit->subjectId);

        return $directory === null ? null : route('files.browse', $directory);
    }

    public function render(): View
    {
        return view('livewire.search.palette', ['hits' => $this->hits()]);
    }
}

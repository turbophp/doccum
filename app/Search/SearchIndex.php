<?php

declare(strict_types=1);

namespace App\Search;

use App\Models\SearchDocument;
use Illuminate\Support\Collection;

/**
 * The keyword search seam.
 *
 * Implementations differ in how they rank, not in what they are allowed to
 * return: every one MUST constrain results to the viewer's reachable
 * directories inside the query. Filtering afterwards still leaks the existence
 * of documents through counts and timing. See spec §8.
 */
interface SearchIndex
{
    public function put(SearchDocument $document): void;

    /**
     * Drops whatever auxiliary structure this implementation keeps for the
     * document. That is NOT, on its own, a promise that the document stops
     * being findable: where the projection row IS the index -- the LIKE
     * fallback -- there is nothing separate to drop, and the row's own
     * deletion is what removes it.
     *
     * "No longer searchable" is {@see \App\Services\SearchIndexer::forget()},
     * which calls this and then deletes the row, in that order. Callers want
     * that one; this is the half of it an implementation owns.
     */
    public function forget(SearchDocument $document): void;

    /**
     * @param  array<int, int>  $viewableDirectoryIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, SearchHit>
     */
    public function search(string $query, array $viewableDirectoryIds, array $filters = [], int $limit = 50): Collection;
}

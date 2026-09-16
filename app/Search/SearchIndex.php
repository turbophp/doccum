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

    public function forget(SearchDocument $document): void;

    /**
     * @param  array<int, int>  $viewableDirectoryIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, SearchHit>
     */
    public function search(string $query, array $viewableDirectoryIds, array $filters = [], int $limit = 50): Collection;
}

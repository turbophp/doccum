<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Search\SearchHit;
use App\Search\SearchIndex;
use Illuminate\Support\Collection;

/**
 * The only way anything should search.
 *
 * It resolves what the viewer may reach and hands that to the index, so no
 * caller -- a page, the API, a future export -- can forget to. Leaving that to
 * callers means one that forgets leaks the whole corpus, and it would look like
 * working code. See spec §5 and §8.
 */
class Search
{
    public function __construct(
        private readonly SearchIndex $index,
        private readonly DirectoryAccess $access,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, SearchHit>
     */
    public function for(User $user, string $query, array $filters = [], int $limit = 50): Collection
    {
        // Resolved per search rather than baked into the index: a revoked
        // grant must take effect on the next query, not the next reindex.
        return $this->index->search(
            $query,
            $this->access->viewableDirectoryIds($user),
            $filters,
            $limit,
        );
    }
}

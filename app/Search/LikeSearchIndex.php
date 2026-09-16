<?php

declare(strict_types=1);

namespace App\Search;

use App\Models\SearchDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Correct but unranked keyword search for drivers without a full-text index.
 *
 * A safety net, not a destination: it finds the right documents and enforces
 * the same access rules, but cannot say which is the better match. Postgres
 * gets tsvector and a hosted engine is another implementation; see spec §8.
 */
class LikeSearchIndex implements SearchIndex
{
    /** The projection row IS the index here, so there is nothing separate to write. */
    public function put(SearchDocument $document): void {}

    public function forget(SearchDocument $document): void {}

    public function search(string $query, array $viewableDirectoryIds, array $filters = [], int $limit = 50): Collection
    {
        $words = Terms::words($query);

        if ($words === [] || $viewableDirectoryIds === []) {
            return new Collection;
        }

        $documents = SearchDocument::query()
            ->whereIn('directory_id', $viewableDirectoryIds)
            ->where(function (Builder $outer) use ($words): void {
                foreach ($words as $word) {
                    $outer->where(function (Builder $inner) use ($word): void {
                        $inner->where('title', 'like', '%'.$word.'%')
                            ->orWhere('body', 'like', '%'.$word.'%');
                    });
                }
            })
            ->when(isset($filters['period_year']), fn (Builder $q) => $q->where('period_year', $filters['period_year']))
            ->when(isset($filters['period_month']), fn (Builder $q) => $q->where('period_month', $filters['period_month']))
            ->when(isset($filters['mime']), fn (Builder $q) => $q->where('mime', $filters['mime']))
            ->when(isset($filters['extension']), fn (Builder $q) => $q->where('extension', $filters['extension']))
            ->when(isset($filters['subject_type']), fn (Builder $q) => $q->where('subject_type', $filters['subject_type']))
            ->orderBy('title')
            ->limit($limit)
            ->get();

        return $documents->map(static fn (SearchDocument $d): SearchHit => SearchHit::fromDocument($d, 0.0))->values();
    }
}

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

        // Postgres is the only driver here whose LIKE is case-sensitive:
        // SQLite's is not, and MySQL's default utf8mb4 collation is not
        // either. Left as a plain LIKE, searching "quarterly" simply never
        // found "Quarterly-Report.pdf" on Postgres -- no error, no warning,
        // just nothing, which is the worst way for a search box to be wrong.
        $operator = (new SearchDocument)->getConnection()->getDriverName() === 'pgsql'
            ? 'ilike'
            : 'like';

        $documents = SearchDocument::query()
            ->whereIn('directory_id', $viewableDirectoryIds)
            ->where(function (Builder $outer) use ($words, $operator): void {
                foreach ($words as $word) {
                    $outer->where(function (Builder $inner) use ($word, $operator): void {
                        $inner->where('title', $operator, '%'.$word.'%')
                            ->orWhere('body', $operator, '%'.$word.'%');
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

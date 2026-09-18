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

        $driver = (new SearchDocument)->getConnection()->getDriverName();

        // Postgres is the only driver here whose LIKE is case-sensitive:
        // SQLite's is not, and MySQL's default utf8mb4 collation is not
        // either. Left as a plain LIKE, searching "quarterly" simply never
        // found "Quarterly-Report.pdf" on Postgres -- no error, no warning,
        // just nothing, which is the worst way for a search box to be wrong.
        $operator = $driver === 'pgsql' ? 'ilike' : 'like';

        $property = PropertyFilter::fromFilters($filters);

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
            // Same shape as Fts5SearchIndex: an EXISTS against `properties`,
            // ANDed onto the directory_id/permission predicate above, never
            // a query built fresh that could drop it. On PostgreSQL the
            // string/text/select columns also need the md5() predicate
            // alongside the real one, or the planner never picks
            // properties_property_definition_id_value_string_index -- see
            // that migration's comment and PropertyFilter.
            ->when($property !== null, function (Builder $q) use ($property, $driver): void {
                $q->whereExists(function ($sub) use ($property, $driver): void {
                    $sub->from('properties')
                        ->whereColumn('properties.subject_type', 'search_documents.subject_type')
                        ->whereColumn('properties.subject_id', 'search_documents.subject_id')
                        ->where('properties.property_definition_id', $property->definitionId)
                        ->where('properties.'.$property->column, $property->value);

                    if ($driver === 'pgsql' && $property->isStringColumn()) {
                        $sub->whereRaw('md5(properties.value_string) = md5(?)', [$property->value]);
                    }
                });
            })
            ->orderBy('title')
            ->limit($limit)
            ->get();

        return $documents->map(static fn (SearchDocument $d): SearchHit => SearchHit::fromDocument($d, 0.0))->values();
    }
}

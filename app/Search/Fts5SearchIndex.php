<?php

declare(strict_types=1);

namespace App\Search;

use App\Models\SearchDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keyword search on SQLite's FTS5, ranked by BM25.
 *
 * The virtual table's rowid is the projection row's id, so the two stay in
 * step without triggers and a match joins straight back to everything needed
 * for filtering.
 */
class Fts5SearchIndex implements SearchIndex
{
    /** Titles are weighted far above bodies: matching a name is a stronger signal than matching one word in fifty pages. */
    private const TITLE_WEIGHT = 10.0;

    private const BODY_WEIGHT = 1.0;

    public function put(SearchDocument $document): void
    {
        DB::statement(
            'INSERT OR REPLACE INTO search_index(rowid, title, body) VALUES (?, ?, ?)',
            [$document->getKey(), $document->title, (string) $document->body],
        );
    }

    public function forget(SearchDocument $document): void
    {
        DB::statement('DELETE FROM search_index WHERE rowid = ?', [$document->getKey()]);
    }

    public function search(string $query, array $viewableDirectoryIds, array $filters = [], int $limit = 50): Collection
    {
        $match = Terms::toFts5($query);

        // Nothing searchable was typed. An empty query must return nothing
        // rather than everything -- "show me all documents" is browsing, and
        // it is not what someone pressing enter on an empty box asked for.
        if ($match === null || $viewableDirectoryIds === []) {
            return new Collection;
        }

        // The permission predicate and the bindings that feed it are built
        // TOGETHER, in one contiguous block, and both start empty above it.
        // That shape is for mutations.json: removing the block leaves
        // `$permission = ''` and `$permissionBindings = []`, so the query is
        // still VALID SQL and simply stops restricting by directory -- it
        // returns rows the viewer cannot reach, which is a real leak a test
        // can catch.
        //
        // Built any other way, the two halves are not removable together: an
        // entry that deletes only the SQL fragment leaves the directory ids
        // in $bindings, the placeholder count desyncs, and SQLite throws. The
        // guard would then "fail when removed" by proving that SQLite counts
        // placeholders, not that this predicate keeps unreachable documents
        // out. DirectoryPolicy's entry carries the same reasoning for a
        // policy clause, and this is the search-side twin of it.
        $permission = '';
        $permissionBindings = [];

        $permission = ' AND d.directory_id IN ('.implode(',', array_fill(0, count($viewableDirectoryIds), '?')).')';
        $permissionBindings = array_values($viewableDirectoryIds);

        $bindings = [$match, ...$permissionBindings];

        $where = '';
        foreach (['period_year', 'period_month', 'mime', 'extension', 'subject_type'] as $filter) {
            if (isset($filters[$filter])) {
                $where .= " AND d.{$filter} = ?";
                $bindings[] = $filters[$filter];
            }
        }

        // The property filter cannot be a plain `d.{column} = ?` -- the value
        // lives on `properties`, keyed by the SAME subject the projection row
        // was built for, not on `search_documents` itself. An EXISTS keeps it
        // inside this one query, ANDed onto the directory_id/permission
        // predicate above rather than replacing it: SQLite only, so no
        // md5() predicate is needed (that is PostgreSQL's index only; see
        // PropertyFilter and the properties migration's comment).
        $property = PropertyFilter::fromFilters($filters);

        if ($property !== null) {
            $where .= ' AND EXISTS (
                SELECT 1 FROM properties p
                WHERE p.subject_type = d.subject_type
                  AND p.subject_id = d.subject_id
                  AND p.property_definition_id = ?
                  AND p.'.$property->column.' = ?
            )';
            $bindings[] = $property->definitionId;
            $bindings[] = is_bool($property->value) ? (int) $property->value : $property->value;
        }

        $bindings[] = $limit;

        // bm25() is negative in SQLite, and more negative is a better match,
        // so ascending order puts the best first.
        $rows = DB::select(
            'SELECT d.id, bm25(search_index, '.self::TITLE_WEIGHT.', '.self::BODY_WEIGHT.') AS score
             FROM search_index
             JOIN search_documents d ON d.id = search_index.rowid
             WHERE search_index MATCH ?'.$permission.$where.'
             ORDER BY score ASC
             LIMIT ?',
            $bindings,
        );

        $documents = SearchDocument::query()
            ->whereIn('id', array_map(static fn (object $row): int => (int) $row->id, $rows))
            ->get()
            ->keyBy('id');

        return (new Collection($rows))
            ->map(function (object $row) use ($documents): ?SearchHit {
                $document = $documents->get((int) $row->id);

                return $document === null ? null : SearchHit::fromDocument($document, (float) $row->score);
            })
            ->filter()
            ->values();
    }
}

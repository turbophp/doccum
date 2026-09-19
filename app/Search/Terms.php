<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Turns what a person typed into something an engine can be handed safely.
 *
 * FTS5 has its own query language: `"`, `*`, `NEAR(`, `OR`, `^` and `:` all
 * mean something, so raw input is a syntax error waiting to happen -- and a
 * syntax error in production, not a polite no-match. Everything is reduced to
 * words, which are then quoted as literal terms.
 */
final class Terms
{
    /** @return array<int, string> */
    public static function words(string $query): array
    {
        return array_values(array_filter(
            preg_split('/[^\p{L}\p{N}_]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn (string $word): bool => mb_strlen($word) > 0,
        ));
    }

    /**
     * Shortest word that is treated as the start of a longer one.
     *
     * Below this a prefix matches most of the vocabulary, which is not a
     * search: "a" would return every document containing any word beginning
     * with A, ranked by a BM25 score computed over noise. Two letters is
     * already marginal; three is where a prefix starts meaning something.
     */
    private const PREFIX_MINIMUM = 3;

    /** An FTS5 MATCH expression, or null when nothing searchable was typed. */
    public static function toFts5(string $query): ?string
    {
        $words = self::words($query);

        if ($words === []) {
            return null;
        }

        // Terms are prefixes, so "invo" finds "invoice".
        //
        // This closes a gap that ran the wrong way round: LikeSearchIndex --
        // what MySQL and PostgreSQL use -- has always matched %word%, so a
        // partial word found documents there and found nothing on SQLite,
        // which is the embedded default every single-container install runs.
        // The same query behaving differently depending on which database an
        // operator chose is worse than it doing less everywhere.
        //
        // It does not close the gap entirely: a prefix finds "invoice" from
        // "invo" but not from "nvoice", where LIKE still would. Matching
        // inside a word needs FTS5's trigram tokenizer, which is a second
        // virtual table and a reindex, and is worth doing only if people
        // actually search that way.
        //
        // The index carries no `prefix=` option, so these are answered by
        // scanning the term index rather than a prepared prefix index. That
        // is the right trade at this scale -- adding one means recreating the
        // virtual table and reindexing every document -- and the place to
        // revisit if a large corpus makes search feel slow.
        return implode(' ', array_map(
            static function (string $word): string {
                $quoted = '"'.str_replace('"', '', $word).'"';

                return mb_strlen($word) >= self::PREFIX_MINIMUM ? $quoted.'*' : $quoted;
            },
            $words,
        ));
    }
}

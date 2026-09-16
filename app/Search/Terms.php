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

    /** An FTS5 MATCH expression, or null when nothing searchable was typed. */
    public static function toFts5(string $query): ?string
    {
        $words = self::words($query);

        if ($words === []) {
            return null;
        }

        return implode(' ', array_map(
            static fn (string $word): string => '"'.str_replace('"', '', $word).'"',
            $words,
        ));
    }
}

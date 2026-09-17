<?php

declare(strict_types=1);

namespace App\Support;

use Normalizer;

/**
 * The comparison key behind sibling name uniqueness.
 *
 * Two names are the same sibling name when they are equal case-insensitively
 * and accent-SENSITIVELY, after NFC normalisation. Accent-sensitivity is the
 * axis that rules out `LOWER(name) = ?`: on MySQL the column collation folds
 * accents as well as case, so `résumé.pdf` and `resume.pdf` would collide.
 * NFC normalisation is the axis that rules out a byte comparison: macOS
 * clients historically submit NFD filenames (a base letter followed by a
 * combining accent), which would otherwise split one abstract name in two.
 * See issue #46's decision comment.
 *
 * Pure and database-free by design, exactly like ObjectKey -- so the rule is
 * unit-testable on its own and the models can call it from a `saving` hook
 * without pulling in anything else.
 */
final class NameKey
{
    public static function of(string $name): string
    {
        $normalised = Normalizer::normalize($name, Normalizer::FORM_C);

        // Normalizer::normalize() returns false on malformed input (invalid
        // UTF-8 sequences); falling back to the original string keeps this
        // method total rather than throwing on a name nothing else rejected.
        if ($normalised === false) {
            $normalised = $name;
        }

        return mb_strtolower($normalised);
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown for a key that names no definition, or names one that does not
 * apply to the subject type being written.
 *
 * Never caught and ignored: silently discarding an unknown key would let a UI
 * bug drop data with no signal. See plan Task 4.
 */
class UnknownProperty extends RuntimeException
{
    public static function key(string $key): self
    {
        return new self(sprintf('"%s" is not a property that applies to this subject.', $key));
    }
}

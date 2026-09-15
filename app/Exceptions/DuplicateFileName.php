<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class DuplicateFileName extends RuntimeException
{
    public static function in(int $directoryId, string $name): self
    {
        return new self(sprintf('A file named "%s" already exists in directory %d.', $name, $directoryId));
    }
}

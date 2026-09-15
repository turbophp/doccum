<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class DuplicateDirectoryName extends RuntimeException
{
    public static function in(?int $parentId, string $name): self
    {
        return new self(sprintf(
            'A directory named "%s" already exists in %s.',
            $name,
            $parentId === null ? 'the root' : "directory {$parentId}",
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\File;
use RuntimeException;

/**
 * Thrown when an action would remove a file that is under legal hold.
 * A hold that does not stop deletion is decoration: see spec §9.
 */
class FileIsUnderLegalHold extends RuntimeException
{
    public static function forFile(File $file): self
    {
        return new self(sprintf('File %d is under legal hold and cannot be trashed.', $file->getKey()));
    }
}

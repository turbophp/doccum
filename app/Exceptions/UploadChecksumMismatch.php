<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the sha256 of a staged object's bytes does not match the
 * checksum the commit request declares. See App\Actions\Files\CommitUpload
 * and spec §11's upload flow.
 */
class UploadChecksumMismatch extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('The staged object at "%s" does not match the declared checksum.', $key));
    }
}

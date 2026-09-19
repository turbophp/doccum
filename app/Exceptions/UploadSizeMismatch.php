<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the object a client PUT to staging is not the size the
 * upload_id declared -- a truncated upload, or a client that requested a
 * URL for one file and sent another. See App\Actions\Files\CommitUpload and
 * spec §11's upload flow.
 */
class UploadSizeMismatch extends RuntimeException
{
    public static function forKey(string $key, int $declared, int $actual): self
    {
        return new self(sprintf(
            'The staged object at "%s" is %d bytes, but %d bytes were declared.',
            $key,
            $actual,
            $declared,
        ));
    }
}

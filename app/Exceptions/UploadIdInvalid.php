<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an upload_id cannot be trusted: it fails to decrypt, is
 * missing a field CommitUpload needs, or names a directory other than the
 * one the commit request itself names. See App\Actions\Files\CreateUploadUrl
 * and App\Actions\Files\CommitUpload, and spec §11's upload flow.
 */
class UploadIdInvalid extends RuntimeException
{
    public static function forMismatch(): self
    {
        return new self('This upload_id is invalid, tampered with, or does not belong to this directory.');
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a commit arrives after its upload_id's own TTL has passed.
 * See App\Actions\Files\CreateUploadUrl and spec §11's upload flow.
 */
class UploadIdExpired extends RuntimeException
{
    public static function forUploadId(): self
    {
        return new self('This upload_id has expired. Request a new upload URL and try again.');
    }
}

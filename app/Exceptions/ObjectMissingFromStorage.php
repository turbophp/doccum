<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the object store reports no object for a key a `file_versions`
 * row still points at.
 *
 * `/data` is the only persistent volume (CLAUDE.md), and "restore the
 * database but forget `objects/`" is the likeliest operator mistake in the
 * product. Before this exception existed, that mistake surfaced as a 500 --
 * DocumentStorage::readStream() raised a bare RuntimeException from inside a
 * streamDownload() callback, which Symfony runs during sendContent(), after
 * the status line is already on the wire. The observed code was therefore an
 * accident of timing relative to the header flush, not a decision. A typed
 * exception lets a caller open the stream BEFORE constructing the response,
 * so the status is chosen rather than raced. See
 * FileDownloadController/FileVersionDownloadController and issue #134.
 */
class ObjectMissingFromStorage extends RuntimeException
{
    public static function forKey(string $objectKey): self
    {
        return new self("Object [{$objectKey}] was not found in storage.");
    }
}

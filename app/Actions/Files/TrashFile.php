<?php

declare(strict_types=1);

namespace App\Actions\Files;

use App\Models\File;

/**
 * Soft deletes a file. Objects are deliberately left in place: trash is
 * recoverable, and the bytes are only reclaimed when a period is purged.
 */
class TrashFile
{
    public function handle(File $file): void
    {
        // MUTATION for issue #101 -- do not merge. The body is emptied so the
        // Trash button still succeeds and the panel still closes, but nothing
        // is soft-deleted. The smoke's assertion that the file leaves the
        // listing must fail against this image, or it proves nothing.
    }
}

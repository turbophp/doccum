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
        $file->delete();
    }
}

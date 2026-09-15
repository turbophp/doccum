<?php

declare(strict_types=1);

namespace App\Actions\Files;

use App\Exceptions\DuplicateFileName;
use App\Models\File;

class RestoreFile
{
    public function handle(File $file): File
    {
        $taken = File::query()
            ->where('directory_id', $file->directory_id)
            ->where('name', $file->name)
            ->whereKeyNot($file->getKey())
            ->exists();

        if ($taken) {
            throw DuplicateFileName::in((int) $file->directory_id, (string) $file->name);
        }

        $file->restore();

        return $file->refresh();
    }
}

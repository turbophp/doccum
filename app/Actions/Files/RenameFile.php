<?php

declare(strict_types=1);

namespace App\Actions\Files;

use App\Exceptions\DuplicateFileName;
use App\Exceptions\PeriodIsArchived;
use App\Models\ArchivePeriod;
use App\Models\File;

/**
 * Renames a file within its current directory.
 *
 * This action never authorises -- the caller does, through FilePolicy.
 */
class RenameFile
{
    public function handle(File $file, string $name): File
    {
        // A file's period is fixed at creation, so renaming it is writing
        // back into that same period: an archived one must refuse it exactly
        // as it refuses a new version.
        if (ArchivePeriod::isArchivedFor((int) $file->period_year, (int) $file->period_month)) {
            throw PeriodIsArchived::forPeriod((int) $file->period_year, (int) $file->period_month);
        }

        $taken = File::query()
            ->where('directory_id', $file->directory_id)
            ->where('name', $name)
            ->whereKeyNot($file->getKey())
            ->exists();

        if ($taken) {
            throw DuplicateFileName::in((int) $file->directory_id, $name);
        }

        $file->update(['name' => $name]);

        return $file->refresh();
    }
}

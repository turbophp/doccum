<?php

declare(strict_types=1);

namespace App\Actions\Files;

use App\Exceptions\PeriodIsArchived;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;

/**
 * Moves a file into a different directory. A file's period is fixed at
 * creation and never changes -- only the directory it hangs off of does.
 *
 * This action never authorises -- the caller does, through FilePolicy.
 */
class MoveFile
{
    public function handle(File $file, Directory $directory): File
    {
        // Moving is writing back into the file's own (fixed) period, so an
        // archived period must refuse it exactly as it refuses a new version.
        if (ArchivePeriod::isArchivedFor((int) $file->period_year, (int) $file->period_month)) {
            throw PeriodIsArchived::forPeriod((int) $file->period_year, (int) $file->period_month);
        }

        $file->update(['directory_id' => $directory->getKey()]);

        return $file->refresh();
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Files;

use App\Exceptions\DuplicateFileName;
use App\Exceptions\PeriodIsArchived;
use App\Models\ArchivePeriod;
use App\Models\File;

class RestoreFile
{
    public function handle(File $file): File
    {
        // A file's period is fixed at creation, so restoring it is writing
        // back into that same period: an archived one must refuse it exactly
        // as it refuses a new version.
        if (ArchivePeriod::isArchivedFor((int) $file->period_year, (int) $file->period_month)) {
            throw PeriodIsArchived::forPeriod((int) $file->period_year, (int) $file->period_month);
        }

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

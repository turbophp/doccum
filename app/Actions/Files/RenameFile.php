<?php

declare(strict_types=1);

namespace App\Actions\Files;

use App\Exceptions\DuplicateFileName;
use App\Exceptions\PeriodIsArchived;
use App\Models\ArchivePeriod;
use App\Models\File;

/**
 * Renames a file in place. Authorisation is the CALLER's job, through
 * FilePolicy.
 *
 * A plain save() is enough to keep search current: SearchProjectionObserver
 * reindexes the file itself on its `saved` event, and a rename touches
 * neither `directory_id` nor any property, so nothing else can go stale.
 */
class RenameFile
{
    public function handle(File $file, string $newName): File
    {
        // A file's period is fixed at creation, so this writes back into that
        // same period: an archived one must refuse it exactly as it refuses a
        // new version.
        if (ArchivePeriod::isArchivedFor((int) $file->period_year, (int) $file->period_month)) {
            throw PeriodIsArchived::forPeriod((int) $file->period_year, (int) $file->period_month);
        }

        $taken = File::query()
            ->where('directory_id', $file->directory_id)
            ->whereNamed($newName)
            ->whereKeyNot($file->getKey())
            ->exists();

        if ($taken) {
            throw DuplicateFileName::in((int) $file->directory_id, $newName);
        }

        $file->name = $newName;
        $file->save();

        return $file->refresh();
    }
}

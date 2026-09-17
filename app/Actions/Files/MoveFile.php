<?php

declare(strict_types=1);

namespace App\Actions\Files;

use App\Exceptions\DuplicateFileName;
use App\Exceptions\PeriodIsArchived;
use App\Jobs\ReindexSearchDocument;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;

/**
 * Moves a file to a different directory. Authorisation is the CALLER's job,
 * through FilePolicy.
 */
class MoveFile
{
    public function handle(File $file, Directory $newDirectory): File
    {
        // A file's period is fixed at creation, so this writes back into that
        // same period: an archived one must refuse it exactly as it refuses a
        // new version.
        if (ArchivePeriod::isArchivedFor((int) $file->period_year, (int) $file->period_month)) {
            throw PeriodIsArchived::forPeriod((int) $file->period_year, (int) $file->period_month);
        }

        $taken = File::query()
            ->where('directory_id', $newDirectory->getKey())
            ->whereNamed((string) $file->name)
            ->whereKeyNot($file->getKey())
            ->exists();

        if ($taken) {
            throw DuplicateFileName::in((int) $newDirectory->getKey(), (string) $file->name);
        }

        $file->directory_id = $newDirectory->getKey();
        $file->save();

        $file = $file->refresh();

        // save() above reindexes the file itself through
        // SearchProjectionObserver's `saved` hook. A property row is
        // untouched by the move, so no `saved` event fires for it --
        // reindexing it here is the only thing that keeps its ancestor_ids
        // (derived from its subject's directory, see SearchIndexer::forProperty())
        // from going stale. Same reasoning as MoveDirectory::reindexSubtree().
        foreach ($file->properties as $property) {
            ReindexSearchDocument::dispatch($property);
        }

        return $file;
    }
}

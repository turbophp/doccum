<?php

declare(strict_types=1);

namespace App\Actions\Files;

use App\Exceptions\DuplicateFileName;
use App\Jobs\ReindexSearchDocument;
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

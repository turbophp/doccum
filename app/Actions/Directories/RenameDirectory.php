<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Exceptions\DuplicateDirectoryName;
use App\Models\Directory;

/**
 * Renames a directory in place. Authorisation is the CALLER's job, through
 * DirectoryPolicy.
 *
 * `path` is a materialised path of ancestor IDS, not names (see
 * Directory::syncPath()), so a rename never touches it: syncPath() rebuilds
 * the path from parent_id alone, and parent_id is untouched here. No path
 * rewrite, for this directory or any descendant, is needed.
 *
 * A plain save() is also enough to keep search current: SearchProjectionObserver
 * reindexes the directory itself on its `saved` event, and a rename touches
 * neither `path` nor any property, so nothing else can go stale.
 */
class RenameDirectory
{
    public function handle(Directory $directory, string $newName): Directory
    {
        $taken = Directory::query()
            ->where('parent_id', $directory->parent_id)
            ->whereNamed($newName)
            ->whereKeyNot($directory->getKey())
            ->exists();

        if ($taken) {
            throw DuplicateDirectoryName::in($directory->parent_id, $newName);
        }

        $directory->name = $newName;
        $directory->save();

        return $directory->refresh();
    }
}

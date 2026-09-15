<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Exceptions\CannotMoveDirectoryIntoItself;
use App\Models\Directory;
use Illuminate\Support\Facades\DB;

class MoveDirectory
{
    public function handle(Directory $directory, ?Directory $newParent): Directory
    {
        if ($newParent !== null && ($newParent->is($directory) || $newParent->isDescendantOf($directory))) {
            throw new CannotMoveDirectoryIntoItself();
        }

        $oldPath = $directory->path;

        DB::transaction(function () use ($directory, $newParent, $oldPath): void {
            $directory->parent_id = $newParent?->getKey();
            $directory->save();
            $directory->syncPath();

            $newPath = $directory->refresh()->path;

            // Rewrite the prefix on every descendant. REPLACE is safe here
            // because $oldPath is a leading run of unique ids, so that exact
            // substring cannot recur further along any descendant path.
            DB::statement(
                'UPDATE directories SET path = REPLACE(path, ?, ?) WHERE path LIKE ? AND id <> ?',
                [$oldPath, $newPath, $oldPath.'%', $directory->getKey()],
            );

            // Recompute depth from the rewritten path. Counting separators this
            // way is the one expression that behaves identically on SQLite,
            // MySQL, and Postgres.
            DB::statement(
                "UPDATE directories SET depth = LENGTH(path) - LENGTH(REPLACE(path, '/', '')) - 2 WHERE path LIKE ?",
                [$newPath.'%'],
            );
        });

        return $directory->refresh();
    }
}

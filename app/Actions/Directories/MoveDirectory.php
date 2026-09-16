<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Exceptions\CannotMoveDirectoryIntoItself;
use App\Jobs\ReindexSearchDocument;
use App\Models\Directory;
use App\Models\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MoveDirectory
{
    public function handle(Directory $directory, ?Directory $newParent): Directory
    {
        if ($newParent !== null && ($newParent->is($directory) || $newParent->isDescendantOf($directory))) {
            throw new CannotMoveDirectoryIntoItself;
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

        $directory = $directory->refresh();

        // Rewritten with raw SQL above rather than through Eloquent, so no
        // model event fired for a single descendant -- reindexing the whole
        // subtree here, after the transaction has returned, is the only
        // thing that keeps every descendant's ancestor_ids (what access
        // filtering runs against) from going stale.
        $this->reindexSubtree($directory);

        return $directory;
    }

    private function reindexSubtree(Directory $root): void
    {
        $directories = (new Collection([$root]))->concat($root->descendants()->get());

        foreach ($directories as $node) {
            ReindexSearchDocument::dispatch($node);

            foreach ($node->properties as $property) {
                ReindexSearchDocument::dispatch($property);
            }

            foreach (File::query()->where('directory_id', $node->getKey())->get() as $file) {
                ReindexSearchDocument::dispatch($file);

                foreach ($file->properties as $property) {
                    ReindexSearchDocument::dispatch($property);
                }
            }
        }
    }
}

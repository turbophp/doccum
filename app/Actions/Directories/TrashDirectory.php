<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Models\Directory;
use App\Models\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes a directory and everything beneath it.
 *
 * Every descendant directory and every file in the subtree is soft-deleted
 * per row, through Eloquent, rather than with a mass update -- that is what
 * makes SearchProjectionObserver::deleted() fire for each one and forget its
 * projection, so "gone from the browser and from search" is true by
 * construction rather than something a filter has to get right on every
 * path. See App\Services\DirectoryAccess for the defensive, query-level
 * backstop and issue #49 for why both exist.
 *
 * Deliberately synchronous, not a queued job: "vanishes immediately" would
 * otherwise be false. The per-row cost is accepted for v1 -- trashing a
 * directory is rare, and the subtrees in practice are not enormous.
 *
 * A row that is already trashed independently of this one -- a file someone
 * deleted before its directory was, or a descendant directory trashed on its
 * own -- is left alone: SoftDeletes' global scope excludes it from every
 * query here, so its own deleted_at survives untouched. That is exactly what
 * lets RestoreDirectory tell the two apart.
 */
class TrashDirectory
{
    public function handle(Directory $directory): Directory
    {
        DB::transaction(function () use ($directory): void {
            $deletedAt = now();

            $subtree = (new Collection([$directory]))->concat($directory->descendants()->get());

            foreach ($subtree as $node) {
                $node->delete();

                // delete() stamps its own fresh timestamp; pinned to the same
                // value across every row so RestoreDirectory's "deleted_at
                // equals the root's" comparison is exact, not a race against
                // the clock between the first row and the last.
                $node->forceFill(['deleted_at' => $deletedAt])->saveQuietly();

                foreach (File::query()->where('directory_id', $node->getKey())->get() as $file) {
                    $file->delete();
                    $file->forceFill(['deleted_at' => $deletedAt])->saveQuietly();
                }
            }
        });

        return $directory->refresh();
    }
}

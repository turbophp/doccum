<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Models\Directory;
use App\Models\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
 * Every row this cascade trashes is stamped with one trashed_batch, which is
 * what RestoreDirectory reverses. A row already trashed independently is
 * left alone -- SoftDeletes' global scope excludes it from every query here,
 * so it keeps whatever batch (or none) it already had.
 */
class TrashDirectory
{
    public function handle(Directory $directory): Directory
    {
        DB::transaction(function () use ($directory): void {
            $batch = (string) Str::uuid();

            // Snapshotted before anything is deleted, and walked as two
            // concrete loops rather than one concatenated collection: the
            // concat erases the element type, and the root and its
            // descendants are the same work either way.
            $descendants = $directory->descendants()->get();

            $this->trashWithFiles($directory, $batch);

            foreach ($descendants as $descendant) {
                $this->trashWithFiles($descendant, $batch);
            }
        });

        return $directory->refresh();
    }

    private function trashWithFiles(Directory $directory, string $batch): void
    {
        $this->trash($directory, $batch);

        foreach (File::query()->where('directory_id', $directory->getKey())->get() as $file) {
            $this->trash($file, $batch);
        }
    }

    /**
     * The batch is written before the delete, quietly, so that by the time
     * SearchProjectionObserver::deleted() runs the row already carries it.
     * delete() itself would not persist it: soft-deleting writes only
     * deleted_at and updated_at, never the model's other dirty attributes.
     */
    private function trash(Directory|File $node, string $batch): void
    {
        $node->forceFill(['trashed_batch' => $batch])->saveQuietly();
        $node->delete();
    }
}

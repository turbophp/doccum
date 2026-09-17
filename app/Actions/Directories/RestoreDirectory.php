<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Exceptions\DuplicateDirectoryName;
use App\Models\Directory;
use App\Models\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reverses TrashDirectory: restores the directory and every descendant
 * directory and file that was trashed as part of the same cascade.
 *
 * "Same cascade" is decided by the root's trashed_batch, not by matching
 * deleted_at -- a file or descendant directory trashed independently
 * beforehand must stay trashed, and restoring the whole subtree
 * unconditionally would resurrect it, which is not what "restore reverses
 * it" means. deleted_at cannot make that distinction: it is stored at second
 * precision, so a file trashed moments before its directory carries a
 * byte-identical timestamp. See TrashDirectory and issue #49.
 */
class RestoreDirectory
{
    public function handle(Directory $directory): Directory
    {
        $batch = $directory->trashed_batch;

        $taken = Directory::query()
            ->where('parent_id', $directory->parent_id)
            ->whereNamed((string) $directory->name)
            ->whereKeyNot($directory->getKey())
            ->exists();

        if ($taken) {
            throw DuplicateDirectoryName::in($directory->parent_id, (string) $directory->name);
        }

        DB::transaction(function () use ($directory, $batch): void {
            $this->restore($directory);

            // withTrashed(), not the default query: these rows are exactly
            // the ones the ordinary scope is built to hide, and only the
            // ones carrying the root's batch belong to this cascade.
            $descendants = Directory::withTrashed()
                ->where('path', 'like', $directory->path.'%')
                ->whereKeyNot($directory->getKey())
                ->where('trashed_batch', $batch)
                ->get();

            foreach ($descendants as $descendant) {
                $this->restore($descendant);
            }

            $directoryIds = (new Collection([$directory]))->concat($descendants)->pluck('id');

            $files = File::withTrashed()
                ->whereIn('directory_id', $directoryIds)
                ->where('trashed_batch', $batch)
                ->get();

            foreach ($files as $file) {
                $this->restore($file);
            }
        });

        return $directory->refresh();
    }

    /**
     * The batch is cleared as the row comes back, so a row restored and later
     * trashed on its own is never mistaken for part of this cascade again.
     */
    private function restore(Directory|File $node): void
    {
        $node->restore();
        $node->forceFill(['trashed_batch' => null])->saveQuietly();
    }
}

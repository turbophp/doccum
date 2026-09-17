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
 * "Same cascade" is decided by an exact match on deleted_at against the
 * root's own value, not merely "is trashed" -- a file or descendant
 * directory trashed independently, before this one was, has its own
 * deleted_at and must stay trashed. Restoring the whole subtree
 * unconditionally would resurrect it too, which is not what "restore
 * reverses it" means. See TrashDirectory and issue #49.
 */
class RestoreDirectory
{
    public function handle(Directory $directory): Directory
    {
        $deletedAt = $directory->deleted_at;

        $taken = Directory::query()
            ->where('parent_id', $directory->parent_id)
            ->whereNamed((string) $directory->name)
            ->whereKeyNot($directory->getKey())
            ->exists();

        if ($taken) {
            throw DuplicateDirectoryName::in($directory->parent_id, (string) $directory->name);
        }

        DB::transaction(function () use ($directory, $deletedAt): void {
            $directory->restore();

            // withTrashed(), not the default query: these rows are exactly
            // the ones the ordinary scope is built to hide, and only the
            // ones sharing the root's timestamp belong to this cascade.
            $descendants = Directory::withTrashed()
                ->where('path', 'like', $directory->path.'%')
                ->whereKeyNot($directory->getKey())
                ->where('deleted_at', $deletedAt)
                ->get();

            foreach ($descendants as $descendant) {
                $descendant->restore();
            }

            $directoryIds = (new Collection([$directory]))->concat($descendants)->pluck('id');

            $files = File::withTrashed()
                ->whereIn('directory_id', $directoryIds)
                ->where('deleted_at', $deletedAt)
                ->get();

            foreach ($files as $file) {
                $file->restore();
            }
        });

        return $directory->refresh();
    }
}

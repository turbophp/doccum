<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Models\Directory;
use App\Models\File;
use App\Models\Property;
use App\Services\DocumentStorage;
use App\Services\SearchIndexer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cleans up everything a force-deleted directory's SQL cascade is about to
 * remove without PHP ever running.
 *
 * files.directory_id carries an ON DELETE CASCADE foreign key (see
 * App\Actions\Files\PurgeFile's docblock, which names this exact gap), so
 * force-deleting a Directory row deletes every descendant File row in SQL,
 * in one statement, without a single Eloquent event firing. Nothing routes
 * those objects through DocumentStorage, and nothing tells the search index
 * those rows are gone -- both of which PurgeFile and PeriodPurger get right
 * only because they force-delete one File model at a time. This action is
 * what makes force-deleting a whole Directory get the same guarantees.
 *
 * Called from Directory::booted()'s forceDeleting hook, before the cascade
 * runs, which is the only time the descendant rows can still be read.
 *
 * Mirrors PurgeFile and PeriodPurger's ordering and reasoning throughout:
 *
 * - Objects are collected and deleted through DocumentStorage before
 *   anything else, because that is the one seam allowed to touch the store.
 * - A property's search projection has to be forgotten while the property
 *   row still exists, and the file/directory row it is attached to is a
 *   mass-deleted-or-about-to-be-cascaded row that fires no model events, so
 *   nothing else would ever tell the index to drop it. Worth being exact
 *   about what this buys, because the test below does NOT prove it and
 *   should not be read as though it did: `search_documents` rows carry a
 *   `directory_id` foreign key and so are cascaded away with everything
 *   else, and Fts5SearchIndex::search() INNER JOINs the shadow table back
 *   to them -- so a shadow row whose projection is gone cannot surface in
 *   anybody's results. What forgetting prevents is the shadow table growing
 *   without bound, not a leak. It is here because PurgeFile and
 *   PeriodPurger do it on the file-at-a-time path and an action that skipped
 *   it would read as though the difference were meaningful.
 * - The properties themselves are deleted directly: the morph columns on
 *   `properties` carry no foreign key (a property can point at either a
 *   directory or a file), so the SQL cascade that removes the directory and
 *   file rows does not touch them.
 *
 * The directory's OWN properties are left alone here -- Directory's existing
 * forceDeleted hook already takes those, and it fires (correctly) whether or
 * not this action exists, so handling them again here would just be a second
 * writer for the same row.
 *
 * Never authorises: the caller -- here, the model event itself -- decides
 * whether the force-delete may happen at all.
 */
class PurgeSubtreeOrphans
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly SearchIndexer $indexer,
    ) {}

    public function handle(Directory $directory): void
    {
        // withTrashed, deliberately: Directory::descendants() runs through
        // static::query(), which the SoftDeletes global scope filters, and
        // would miss a subtree that was already soft-deleted independently.
        // The cascade about to run does not consult that scope at all -- it
        // deletes every row whose parent_id chain matches, trashed or not --
        // so reading through the scope here would leave exactly those rows'
        // objects behind.
        $subtreeIds = Directory::withTrashed()
            ->where('path', 'like', $directory->path.'%')
            ->pluck('id')
            ->all();

        File::withTrashed()
            ->whereIn('directory_id', $subtreeIds)
            ->with(['versions', 'properties'])
            ->chunkById(200, function (Collection $files): void {
                $keys = $files
                    ->flatMap(fn (File $file): iterable => $file->versions)
                    ->pluck('object_key')
                    ->filter(fn (?string $key): bool => $key !== null && $key !== '')
                    ->values()
                    ->all();

                // Objects before anything else, exactly as PurgeFile and
                // PeriodPurger order it: if this throws, nothing else in this
                // chunk has been touched yet, so a retry sees the same work.
                if ($keys !== []) {
                    $this->storage->delete(...$keys);
                }

                foreach ($files as $file) {
                    foreach ($file->properties as $property) {
                        $this->indexer->forget($property);
                    }

                    $this->indexer->forget($file);
                }

                Property::query()
                    ->where('subject_type', 'file')
                    ->whereIn('subject_id', $files->pluck('id'))
                    ->delete();
            });

        // Every id in the subtree except the directory itself: its own
        // properties are the existing forceDeleted hook's job, not this one's.
        $descendantIds = array_values(array_diff($subtreeIds, [$directory->getKey()]));

        if ($descendantIds === []) {
            return;
        }

        // Descendant directories get the same treatment their files just got,
        // and for the same reason. SearchIndexer::forDirectory() builds a
        // projection row for a directory exactly as it does for a file, so a
        // subtree of directories that vanishes by cascade leaves the same
        // stale shadow rows behind. Forgetting the files but not the
        // directories they sat in would be an asymmetry with no reason for
        // it, and the next reader would have to work out whether it was
        // deliberate.
        Directory::withTrashed()
            ->whereIn('id', $descendantIds)
            ->with('properties')
            ->chunkById(200, function (Collection $directories): void {
                foreach ($directories as $descendant) {
                    foreach ($descendant->properties as $property) {
                        $this->indexer->forget($property);
                    }

                    $this->indexer->forget($descendant);
                }
            });

        Property::query()
            ->where('subject_type', 'directory')
            ->whereIn('subject_id', $descendantIds)
            ->delete();
    }
}

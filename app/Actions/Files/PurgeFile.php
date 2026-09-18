<?php

declare(strict_types=1);

namespace App\Actions\Files;

use App\Exceptions\FileUnderLegalHold;
use App\Exceptions\PeriodIsArchived;
use App\Models\ArchivePeriod;
use App\Models\File;
use App\Services\DocumentStorage;
use App\Services\SearchIndexer;
use Illuminate\Support\Facades\DB;

/**
 * Permanently deletes one trashed file: every version's object, the file's
 * rows, and its search projection. Irreversible.
 *
 * This action never authorises -- the caller does, through
 * FilePolicy::purge(), which gates on files.delete and manage directory
 * access. See spec §9.
 *
 * Scoped to a single file on purpose, not a directory. A directory purge
 * would need its own action: files.directory_id carries an ON DELETE
 * CASCADE foreign key, so force-deleting a Directory row would cascade at
 * the database level, deleting every descendant File row without ever
 * running this action or DocumentStorage -- which is a storage leak, not a
 * purge. Reclaiming a whole subtree's storage safely needs to visit every
 * descendant file first, which is separate, larger work than this item's
 * size. Whole-period purging already exists as PeriodPurger; this is the
 * per-item complement for a single trashed file, wired to the Trash view by
 * a later item.
 *
 * Two guards refuse before anything is destroyed, mirroring PeriodPurger's:
 *
 * - A file under legal hold: PeriodPurger already blocks a whole period
 *   containing one; this is that same rule at the single-file door a manual
 *   purge opens, so a hold cannot be routed around one file at a time.
 * - A file whose period is archived: an archived period is read-only (see
 *   RestoreFile and StoreFileVersion, which refuse the same way), and the
 *   sanctioned way to reclaim an archived period's storage is the
 *   retention-gated PeriodPurger, not a one-off manual delete that could
 *   leave its file and byte counts inconsistent with what actually
 *   happened.
 *
 * Objects are removed before rows, inside one transaction, the same order
 * and for the same reason as PeriodPurger: if storage deletion throws, the
 * transaction never starts writing, so the row survives and a retry finds
 * the file exactly as it was. The reverse order would leave an object
 * nothing points at, which shows up only as a storage bill.
 */
class PurgeFile
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly SearchIndexer $indexer,
    ) {}

    public function handle(File $file): void
    {
        // MUTATION: purge is a no-op, so the row survives forceDelete.
        return;

        if ($file->legal_hold) {
            throw FileUnderLegalHold::for((int) $file->getKey(), (string) $file->name);
        }

        if (ArchivePeriod::isArchivedFor((int) $file->period_year, (int) $file->period_month)) {
            throw PeriodIsArchived::forPeriod((int) $file->period_year, (int) $file->period_month);
        }

        DB::transaction(function () use ($file): void {
            $keys = $file->versions
                ->pluck('object_key')
                ->filter(fn (?string $key): bool => $key !== null && $key !== '')
                ->values()
                ->all();

            if ($keys !== []) {
                $this->storage->delete(...$keys);
            }

            // A property's projection has to be forgotten while the
            // property still exists: File::forceDeleted mass deletes them,
            // and a mass delete fires no model events, so nothing else
            // would ever remove them from the index. Same reasoning as
            // PeriodPurger.
            foreach ($file->properties as $property) {
                $this->indexer->forget($property);
            }

            $this->indexer->forget($file);

            // Cascades to file_versions and, through them, to file_texts;
            // the forceDeleted hook takes the properties.
            $file->forceDelete();
        });
    }
}

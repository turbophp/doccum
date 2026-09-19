<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DocumentStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Clears staging objects (spec §11's `uploads/{uuid}` prefix) left behind by
 * an upload flow that never reached step 3: a client that requested a
 * presigned PUT URL and never PUT anything, or PUT bytes and never
 * committed them. See App\Actions\Files\CreateUploadUrl and CommitUpload.
 *
 * Only objects OLDER than --older-than are removed -- a fresh staging
 * object from an upload still in flight must survive a sweep that happens
 * to run while it sits there, which is why this checks each object's own
 * last-modified time rather than sweeping the whole staging prefix
 * unconditionally.
 *
 * A backstop, not the only line of defence -- spec §11 also asks for a
 * MinIO lifecycle rule on the same prefix, which this command does not
 * configure (that is provisioning, done in docker/, not application code).
 * Deleting here is safe even if that rule already caught an object: delete()
 * on a key that is already gone is a no-op on every disk this ships with.
 */
class SweepUploads extends Command
{
    protected $signature = 'doccum:sweep-uploads {--older-than=60 : Minutes an object may sit in staging before it is swept.}';

    protected $description = 'Delete staging objects left behind by an upload that was never committed';

    public function handle(DocumentStorage $storage): int
    {
        $prefix = rtrim((string) config('doccum.storage.staging_prefix', 'uploads'), '/');
        $cutoff = Carbon::now()->subMinutes((int) $this->option('older-than'));

        $disk = $storage->disk();
        $swept = 0;

        foreach ($disk->allFiles($prefix) as $path) {
            // allFiles() lists, then lastModified() asks about each entry one
            // at a time, so a commit that promotes and deletes its own staging
            // object in between leaves this loop asking about a path that no
            // longer exists. The previous guard here compared the result to
            // `false`, which PHPStan correctly called unreachable: the method
            // is typed int and signals failure by throwing, so the `continue`
            // could never run and the race it was written for was unhandled.
            // A vanished object is exactly what this sweep wants to skip.
            try {
                $lastModified = $disk->lastModified($path);
            } catch (Throwable) {
                continue;
            }

            if (Carbon::createFromTimestamp($lastModified)->lt($cutoff)) {
                $storage->delete($path);
                $swept++;
            }
        }

        $this->components->info("Swept {$swept} stale staging object(s).");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DocumentStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Clears staging objects (spec §11's `uploads/{uuid}` prefix) left behind by
 * an upload flow that never reached step 3: a client that requested a
 * presigned PUT URL and never PUT anything, or PUT bytes and never
 * committed them. See App\Actions\Files\CreateUploadUrl and CommitUpload.
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
            $lastModified = $disk->lastModified($path);

            if ($lastModified === false) {
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

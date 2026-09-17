<?php

declare(strict_types=1);

namespace App\Actions\Files;

use App\Exceptions\PeriodIsArchived;
use App\Jobs\ExtractText;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\User;
use App\Services\DocumentStorage;
use Illuminate\Support\Facades\DB;

/**
 * Stores an uploaded document, creating the file on first upload and adding a
 * version on every subsequent upload of the same name in the same directory.
 *
 * Authorisation is the CALLER's job (FilePolicy::create). This action assumes
 * the decision has already been made.
 */
class StoreFileVersion
{
    public function __construct(private readonly DocumentStorage $storage) {}

    public function handle(
        User $uploader,
        Directory $directory,
        string $sourcePath,
        string $originalName,
        ?string $mime = null,
    ): File {
        $checksum = @hash_file('sha256', $sourcePath);

        if ($checksum === false) {
            throw new \RuntimeException("Cannot read uploaded file at {$sourcePath}.");
        }

        $size = (int) filesize($sourcePath);
        $mime ??= 'application/octet-stream';

        $file = DB::transaction(function () use ($uploader, $directory, $sourcePath, $originalName, $mime, $size, $checksum): File {
            // Serialises writers within this directory on MySQL and
            // PostgreSQL (SQLite is single-writer anyway). This does not
            // make the same-name race impossible -- a real constraint needs
            // a generated column on MySQL/MariaDB and partial indexes
            // elsewhere, which is separate scheduled work -- but it closes
            // the common case of two concurrent uploads of the same name.
            Directory::query()->whereKey($directory->getKey())->lockForUpdate()->firstOrFail();

            $file = File::query()
                ->where('directory_id', $directory->getKey())
                ->whereNamed($originalName)
                ->first();

            // A file's versions all live under its creation period (see
            // App\Support\ObjectKey), so the period to check is the file's own
            // period when one already exists, and the period it would be
            // created into -- today's -- when it does not.
            $year = $file !== null ? $file->period_year : (int) now()->year;
            $month = $file !== null ? $file->period_month : (int) now()->month;

            if (ArchivePeriod::isArchivedFor($year, $month)) {
                throw PeriodIsArchived::forPeriod($year, $month);
            }

            $file ??= File::create([
                'directory_id' => $directory->getKey(),
                'name' => $originalName,
                'created_by' => $uploader->getKey(),
            ]);

            $versionNumber = (int) $file->versions()->max('version_number') + 1;

            // Inside the transaction on purpose: if the object write throws,
            // the rows roll back rather than leaving a file pointing at
            // storage that holds nothing.
            $objectKey = $this->storage->putVersion($file, $versionNumber, $sourcePath, $originalName);

            $version = FileVersion::create([
                'file_id' => $file->getKey(),
                'version_number' => $versionNumber,
                'object_key' => $objectKey,
                'size' => $size,
                'mime' => $mime,
                'checksum' => $checksum,
                'uploaded_by' => $uploader->getKey(),
            ]);

            $file->update([
                'current_version_id' => $version->getKey(),
                'mime' => $mime,
                'size' => $size,
                'checksum' => $checksum,
            ]);

            return $file->refresh();
        });

        // Dispatched here, after the transaction has returned -- not from a
        // closure passed to DB::transaction() -- so a worker can never pick
        // this job up before the version row it needs is visible. Queued on
        // the ingest queue by the job itself.
        ExtractText::dispatch($file->currentVersion);

        return $file;
    }
}

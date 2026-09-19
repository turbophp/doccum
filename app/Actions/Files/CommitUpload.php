<?php

declare(strict_types=1);

namespace App\Actions\Files;

use App\Exceptions\ObjectMissingFromStorage;
use App\Exceptions\PeriodIsArchived;
use App\Exceptions\UploadChecksumMismatch;
use App\Exceptions\UploadIdExpired;
use App\Exceptions\UploadIdInvalid;
use App\Exceptions\UploadSizeMismatch;
use App\Jobs\ExtractText;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\User;
use App\Services\DocumentStorage;
use App\Support\ObjectKey;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Step 3 of spec §11's upload flow: commits an object the client already
 * PUT to staging in step 2, using the upload_id CreateUploadUrl minted in
 * step 1.
 *
 * Verifies, in order: the upload_id decodes and names THIS directory: the
 * upload_id has not expired; the staged object exists; its size matches
 * what was declared; its sha256 matches the checksum this commit request
 * declares. Only then does it promote the object into its real,
 * period-prefixed key and create the file/version rows -- creating the file
 * on first upload, adding a version on every subsequent commit of the same
 * name in the same directory, the same shape as StoreFileVersion beside it.
 *
 * Authorisation is the CALLER's job (FilePolicy::create). This action
 * assumes the decision has already been made.
 */
class CommitUpload
{
    public function __construct(private readonly DocumentStorage $storage) {}

    public function handle(User $uploader, Directory $directory, string $uploadId, string $checksum): File
    {
        $payload = $this->decode($uploadId);

        if ((int) $payload['directory_id'] !== $directory->getKey()) {
            throw UploadIdInvalid::forMismatch();
        }

        if (Carbon::parse((string) $payload['expires_at'])->isPast()) {
            throw UploadIdExpired::forUploadId();
        }

        $stagingKey = (string) $payload['staging_key'];
        $declaredSize = (int) $payload['size'];
        $name = (string) $payload['name'];
        $mime = (string) $payload['mime'];

        $actualSize = $this->storage->stagedSize($stagingKey);

        if ($actualSize === null) {
            throw ObjectMissingFromStorage::forKey($stagingKey);
        }

        if ($actualSize !== $declaredSize) {
            throw UploadSizeMismatch::forKey($stagingKey, $declaredSize, $actualSize);
        }

        $actualChecksum = $this->storage->stagedChecksum($stagingKey);

        if (! hash_equals($actualChecksum, $checksum)) {
            throw UploadChecksumMismatch::forKey($stagingKey);
        }

        $file = DB::transaction(function () use ($uploader, $directory, $name, $mime, $declaredSize, $actualChecksum, $stagingKey): File {
            // Same serialisation as StoreFileVersion, for the same reason:
            // closes the common case of two concurrent uploads of the same
            // name in the same directory.
            Directory::query()->whereKey($directory->getKey())->lockForUpdate()->firstOrFail();

            $file = File::query()
                ->where('directory_id', $directory->getKey())
                ->whereNamed($name)
                ->first();

            // A file's versions all live under its creation period (see
            // App\Support\ObjectKey), so the period to check is the file's
            // own period when one already exists, and the period it would
            // be created into -- today's -- when it does not.
            $year = $file !== null ? $file->period_year : (int) now()->year;
            $month = $file !== null ? $file->period_month : (int) now()->month;

            if (ArchivePeriod::isArchivedFor($year, $month)) {
                throw PeriodIsArchived::forPeriod($year, $month);
            }

            $file ??= File::create([
                'directory_id' => $directory->getKey(),
                'name' => $name,
                'created_by' => $uploader->getKey(),
            ]);

            $versionNumber = (int) $file->versions()->max('version_number') + 1;

            $finalKey = ObjectKey::forVersion($file, $versionNumber, $name);

            // A storage-side move, not a re-upload: the bytes already sit
            // in the object store from the client's own PUT in step 2, so
            // this never reads them back into PHP. See DocumentStorage::
            // promoteStaging().
            $this->storage->promoteStaging($stagingKey, $finalKey);

            $version = FileVersion::create([
                'file_id' => $file->getKey(),
                'version_number' => $versionNumber,
                'object_key' => $finalKey,
                'size' => $declaredSize,
                'mime' => $mime,
                'checksum' => $actualChecksum,
                'uploaded_by' => $uploader->getKey(),
            ]);

            $file->update([
                'current_version_id' => $version->getKey(),
                'mime' => $mime,
                'size' => $declaredSize,
                'checksum' => $actualChecksum,
            ]);

            return $file->refresh();
        });

        // Dispatched here, after the transaction has returned -- not from a
        // closure passed to DB::transaction() -- so a worker can never pick
        // this job up before the version row it needs is visible. See
        // StoreFileVersion's identical comment.
        ExtractText::dispatch($file->currentVersion);

        return $file;
    }

    /**
     * @return array{directory_id: int, name: string, mime: string, size: int, staging_key: string, uploader_id: int, expires_at: string}
     */
    private function decode(string $uploadId): array
    {
        try {
            $json = Crypt::decryptString($uploadId);
        } catch (DecryptException) {
            throw UploadIdInvalid::forMismatch();
        }

        /** @var mixed $payload */
        $payload = json_decode($json, true);

        if (! is_array($payload)
            || ! isset(
                $payload['directory_id'], $payload['name'], $payload['mime'],
                $payload['size'], $payload['staging_key'], $payload['uploader_id'], $payload['expires_at'],
            )
        ) {
            throw UploadIdInvalid::forMismatch();
        }

        /** @var array{directory_id: int, name: string, mime: string, size: int, staging_key: string, uploader_id: int, expires_at: string} $payload */
        return $payload;
    }
}

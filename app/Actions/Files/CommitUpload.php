<?php

declare(strict_types=1);

namespace App\Actions\Files;

use App\Exceptions\ObjectMissingFromStorage;
use App\Exceptions\UploadChecksumMismatch;
use App\Exceptions\UploadIdExpired;
use App\Exceptions\UploadIdInvalid;
use App\Exceptions\UploadSizeMismatch;
use App\Models\Directory;
use App\Models\File;
use App\Models\User;
use App\Services\DocumentStorage;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Step 3 of spec §11's upload flow: commits an object the client already
 * PUT to staging in step 2, using the upload_id App\Actions\Files\
 * CreateUploadUrl minted in step 1.
 *
 * Two entry points, mirroring CreateUploadUrl's own handle()/forVersion()
 * split: handle() commits a directory-scoped upload_id and appends through
 * StoreFileVersion::handle() -- creating the file on first commit of a name,
 * adding a version on every subsequent one, exactly StoreFileVersion's own
 * create-or-append behaviour. forVersion() commits a file-scoped upload_id
 * and appends through StoreFileVersion::replace(), which cannot create a
 * file -- this is the entry point issue #115 and this item's doneWhen ask
 * for: the per-file versions commit has no Directory and no client
 * filename to pass handle(), so it uses the one entry point that is
 * structurally unable to take the create path.
 *
 * Verifies, in order, for either flow: the upload_id decodes; it has not
 * expired; it names THIS directory or THIS file (never trusts the caller's
 * own id blindly); the staged object exists; its size matches what was
 * declared; its sha256 matches the checksum this commit request declares.
 * A size or checksum mismatch removes the staging object before throwing
 * -- spec's own doneWhen -- so a rejected commit never leaves a stale
 * object behind for the sweep to find later. Any OTHER failure (an
 * archived period discovered inside StoreFileVersion's own transaction, a
 * database error) leaves the staging object alone: it may still be
 * validly re-committable with the same upload_id before that expires, and
 * doccum:sweep-uploads is the backstop for whatever is not.
 *
 * The staged bytes are downloaded once, to a local temporary file, and that
 * same local copy is what verifies the checksum AND what StoreFileVersion
 * uploads under its final key -- never a storage-side move of a key
 * StoreFileVersion knows nothing about, and never a second read of the
 * staged object. See DocumentStorage::downloadStagedToTemp()'s own
 * docblock.
 *
 * Authorisation is the CALLER's job (FilePolicy::create / FilePolicy::
 * replace). This action assumes the decision has already been made.
 */
class CommitUpload
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly StoreFileVersion $storeFileVersion,
    ) {}

    public function handle(User $uploader, Directory $directory, string $uploadId, string $checksum): File
    {
        $payload = $this->decode($uploadId);

        if (($payload['directory_id'] ?? null) !== $directory->getKey()) {
            throw UploadIdInvalid::forMismatch();
        }

        $tempPath = $this->verify($payload, $checksum);

        try {
            $file = $this->storeFileVersion->handle(
                $uploader,
                $directory,
                $tempPath,
                (string) $payload['name'],
                (string) $payload['mime'],
            );
        } finally {
            @unlink($tempPath);
        }

        // Only on success -- see the class docblock for why every other
        // failure path leaves the staging object for the sweep instead.
        $this->storage->delete((string) $payload['staging_key']);

        return $file;
    }

    public function forVersion(User $uploader, File $file, string $uploadId, string $checksum): File
    {
        $payload = $this->decode($uploadId);

        if (($payload['file_id'] ?? null) !== $file->getKey()) {
            throw UploadIdInvalid::forMismatch();
        }

        $tempPath = $this->verify($payload, $checksum);

        try {
            $file = $this->storeFileVersion->replace(
                $uploader,
                $file,
                $tempPath,
                (string) $payload['mime'],
            );
        } finally {
            @unlink($tempPath);
        }

        $this->storage->delete((string) $payload['staging_key']);

        return $file;
    }

    /**
     * @return array<string, mixed>
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
            || ! isset($payload['mime'], $payload['size'], $payload['staging_key'], $payload['uploader_id'], $payload['expires_at'])
            || (! isset($payload['directory_id'], $payload['name']) && ! isset($payload['file_id']))
        ) {
            throw UploadIdInvalid::forMismatch();
        }

        if (Carbon::parse((string) $payload['expires_at'])->isPast()) {
            throw UploadIdExpired::forUploadId();
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * Verifies a staged object's size and checksum against what the
     * upload_id and this commit request declare, and returns a local temp
     * copy of its bytes for the caller to feed into StoreFileVersion.
     *
     * A mismatch removes the staging object before throwing, so a rejected
     * commit never leaves a stale object behind (spec's own doneWhen).
     *
     * @param  array<string, mixed>  $payload
     */
    private function verify(array $payload, string $checksum): string
    {
        $stagingKey = (string) $payload['staging_key'];
        $declaredSize = (int) $payload['size'];

        $actualSize = $this->storage->stagedSize($stagingKey);

        if ($actualSize === null) {
            throw ObjectMissingFromStorage::forKey($stagingKey);
        }

        if ($actualSize !== $declaredSize) {
            $this->storage->delete($stagingKey);

            throw UploadSizeMismatch::forKey($stagingKey, $declaredSize, $actualSize);
        }

        $tempPath = $this->storage->downloadStagedToTemp($stagingKey);

        $actualChecksum = @hash_file('sha256', $tempPath);

        if ($actualChecksum === false) {
            @unlink($tempPath);

            throw new \RuntimeException("Cannot read staged object at {$tempPath}.");
        }

        if (! hash_equals($actualChecksum, $checksum)) {
            @unlink($tempPath);
            $this->storage->delete($stagingKey);

            throw UploadChecksumMismatch::forKey($stagingKey);
        }

        return $tempPath;
    }
}

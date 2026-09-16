<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\File;
use App\Models\FileVersion;
use App\Support\ObjectKey;
use Aws\S3\Exception\S3Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use RuntimeException;

/**
 * The only code in doccum that touches the object store.
 *
 * Everything else composes this service, which is what keeps "point storage at
 * a remote MinIO or at S3" an environment change rather than a code change.
 * See spec §6 and §13.
 */
class DocumentStorage
{
    private bool $bucketEnsured = false;

    public function __construct(private readonly FilesystemManager $filesystem) {}

    public function putVersion(File $file, int $versionNumber, string $sourcePath, string $originalName): string
    {
        $key = ObjectKey::forVersion($file, $versionNumber, $originalName);

        $this->put($key, $sourcePath);

        return $key;
    }

    public function putStaging(string $uploadUuid, string $sourcePath, string $originalName): string
    {
        $key = ObjectKey::staging($uploadUuid, $originalName);

        $this->put($key, $sourcePath);

        return $key;
    }

    /**
     * A short-lived signed URL, so file bytes never stream through PHP.
     * The caller must already have authorised the download.
     */
    public function temporaryUrl(FileVersion $version, int $minutes = 5): string
    {
        return $this->disk()->temporaryUrl($version->object_key, now()->addMinutes($minutes));
    }

    /**
     * Copies a version's bytes to a local temporary file and returns its
     * path, for extraction tools that need a real path on disk rather than
     * a stream. The caller owns the returned file and must remove it.
     */
    public function downloadToTemp(FileVersion $version): string
    {
        $stream = $this->disk()->readStream($version->object_key);

        if ($stream === null) {
            throw new RuntimeException("Unable to read object [{$version->object_key}] from storage.");
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'doccum-extract-');

        if ($tempPath === false) {
            throw new RuntimeException('Unable to create a temporary file for extraction.');
        }

        $destination = fopen($tempPath, 'wb');

        if ($destination === false) {
            throw new RuntimeException("Unable to open {$tempPath} for writing.");
        }

        try {
            stream_copy_to_stream($stream, $destination);
        } finally {
            fclose($destination);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $tempPath;
    }

    public function delete(string ...$objectKeys): void
    {
        $this->disk()->delete($objectKeys);
    }

    public function exists(string $objectKey): bool
    {
        return $this->disk()->exists($objectKey);
    }

    public function size(string $objectKey): int
    {
        return (int) $this->disk()->size($objectKey);
    }

    public function disk(): Filesystem
    {
        return $this->filesystem->disk(config('doccum.storage.disk'));
    }

    /**
     * Creates the configured bucket if it does not already exist.
     *
     * Idempotent and memoised per process: one HEAD request pays for every
     * put() in the same request/job lifecycle. Creating the bucket lazily,
     * here rather than at boot, avoids an ordering problem -- the entrypoint
     * runs before supervisor starts MinIO, so nothing can provision a bucket
     * at container start.
     *
     * A no-op for any disk that is not S3-compatible (including a faked disk
     * in tests), since only the AWS SDK's client exposes headBucket/createBucket.
     */
    public function ensureBucket(): void
    {
        if ($this->bucketEnsured) {
            return;
        }

        $this->bucketEnsured = true;

        $disk = $this->disk();

        if (! method_exists($disk, 'getClient')) {
            return;
        }

        $bucket = (string) config('filesystems.disks.'.config('doccum.storage.disk').'.bucket');

        if ($bucket === '') {
            return;
        }

        $client = $disk->getClient();

        try {
            $client->headBucket(['Bucket' => $bucket]);
        } catch (S3Exception $e) {
            if ((int) $e->getStatusCode() !== 404) {
                throw $e;
            }

            $client->createBucket(['Bucket' => $bucket]);
        }
    }

    private function put(string $key, string $sourcePath): void
    {
        $this->ensureBucket();

        $stream = fopen($sourcePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to open {$sourcePath} for reading.");
        }

        try {
            // Streamed rather than read into memory: an OCR-sized scan should
            // not have to fit in a PHP request's memory limit.
            $this->disk()->writeStream($key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}

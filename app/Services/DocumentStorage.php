<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\File;
use App\Models\FileVersion;
use App\Support\ObjectKey;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;

/**
 * The only code in doccum that touches the object store.
 *
 * Everything else composes this service, which is what keeps "point storage at
 * a remote MinIO or at S3" an environment change rather than a code change.
 * See spec §6 and §13.
 */
class DocumentStorage
{
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

    private function put(string $key, string $sourcePath): void
    {
        $stream = fopen($sourcePath, 'rb');

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

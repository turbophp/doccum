<?php

declare(strict_types=1);

namespace App\Actions\Files;

use App\Models\Directory;
use App\Models\User;
use App\Services\DocumentStorage;
use App\Support\ObjectKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Step 1 of spec §11's upload flow: mints a short-lived presigned PUT URL
 * against the staging prefix, plus a signed upload_id binding directory,
 * name and size, so a large upload never occupies a worker and bytes never
 * pass through PHP.
 *
 * The upload_id is Crypt::encryptString()'d JSON rather than a database row:
 * nothing needs to survive past the one commit it authorises, and an
 * encrypted, self-contained token needs no cleanup job of its own the way a
 * row would. See App\Actions\Files\CommitUpload, which decodes and verifies
 * it.
 *
 * Authorisation is the CALLER's job (FilePolicy::create). This action
 * assumes the decision has already been made.
 */
class CreateUploadUrl
{
    /**
     * How long both the presigned PUT URL and the upload_id itself stay
     * valid. One TTL for both: a client that finishes uploading and then
     * waits past this window must ask for a fresh URL rather than commit
     * against a staging key that may already have been swept away by
     * doccum:sweep-uploads.
     */
    private const TTL_MINUTES = 30;

    public function __construct(private readonly DocumentStorage $storage) {}

    /**
     * @return array{upload_id: string, upload_url: string, upload_headers: array<string, string>, expires_at: string}
     */
    public function handle(User $uploader, Directory $directory, string $name, string $mime, int $size): array
    {
        $stagingKey = ObjectKey::staging((string) Str::uuid(), $name);
        $expiresAt = Carbon::now()->addMinutes(self::TTL_MINUTES);

        $signed = $this->storage->presignedUploadUrl($stagingKey, $mime, self::TTL_MINUTES);

        $uploadId = Crypt::encryptString(json_encode([
            'directory_id' => $directory->getKey(),
            'name' => $name,
            'mime' => $mime,
            'size' => $size,
            'staging_key' => $stagingKey,
            'uploader_id' => $uploader->getKey(),
            'expires_at' => $expiresAt->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return [
            'upload_id' => $uploadId,
            'upload_url' => $signed['url'],
            'upload_headers' => $signed['headers'],
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}

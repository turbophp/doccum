<?php

declare(strict_types=1);

namespace App\Actions\Files;

use App\Exceptions\PeriodIsArchived;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;
use App\Models\User;
use App\Services\DocumentStorage;
use App\Support\ObjectKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Step 1 of spec §11's upload flow: mints a short-lived presigned PUT URL
 * against the staging prefix, plus a signed upload_id, so a large upload
 * never occupies a worker and bytes never pass through PHP.
 *
 * Two entry points, the same shape as StoreFileVersion's handle()/replace()
 * split and for the same reason (issue #115): handle() mints a URL for a
 * DIRECTORY plus a client-declared name -- App\Actions\Files\CommitUpload's
 * own commit step then decides create-or-append by that name, the same as
 * StoreFileVersion::handle() always has. forVersion() mints one for a FILE
 * the caller already holds, with no name involved at all, because the
 * commit step for that upload_id appends through StoreFileVersion::replace()
 * -- which cannot create a file -- never handle().
 *
 * An archived period is refused HERE, at the url step, not left for the
 * commit to discover: spec's own doneWhen asks for the rejection at
 * upload-url, so a caller never gets handed a URL good for nothing. The
 * commit step (CommitUpload -> StoreFileVersion) still re-checks under its
 * own lock, which is what actually protects against a period closing in
 * the gap between the two requests; this check exists purely to fail fast
 * for the ordinary case.
 *
 * The upload_id is Crypt::encryptString()'d JSON rather than a database row:
 * nothing needs to survive past the one commit it authorises, and an
 * encrypted, self-contained token needs no cleanup job of its own the way a
 * row would. See App\Actions\Files\CommitUpload, which decodes and verifies
 * it.
 *
 * Authorisation is the CALLER's job (FilePolicy::create / FilePolicy::
 * replace). This action assumes the decision has already been made.
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
        // The same period-to-check resolution as StoreFileVersion::handle():
        // an existing file's own period when the name already names one in
        // this directory, today's period -- where a fresh file would land
        // -- when it does not.
        $existing = File::query()
            ->where('directory_id', $directory->getKey())
            ->whereNamed($name)
            ->first();

        $year = $existing !== null ? $existing->period_year : (int) now()->year;
        $month = $existing !== null ? $existing->period_month : (int) now()->month;

        if (ArchivePeriod::isArchivedFor($year, $month)) {
            throw PeriodIsArchived::forPeriod($year, $month);
        }

        $stagingKey = ObjectKey::staging((string) Str::uuid(), $name);

        return $this->mint($stagingKey, $mime, $size, $uploader, [
            'directory_id' => $directory->getKey(),
            'name' => $name,
        ]);
    }

    /**
     * @return array{upload_id: string, upload_url: string, upload_headers: array<string, string>, expires_at: string}
     */
    public function forVersion(User $uploader, File $file, string $mime, int $size): array
    {
        if (ArchivePeriod::isArchivedFor((int) $file->period_year, (int) $file->period_month)) {
            throw PeriodIsArchived::forPeriod((int) $file->period_year, (int) $file->period_month);
        }

        $stagingKey = ObjectKey::staging((string) Str::uuid(), $file->name);

        return $this->mint($stagingKey, $mime, $size, $uploader, [
            'file_id' => $file->getKey(),
        ]);
    }

    /**
     * @param  array<string, int|string>  $subject  either ['directory_id' =>, 'name' =>] or ['file_id' =>]
     * @return array{upload_id: string, upload_url: string, upload_headers: array<string, string>, expires_at: string}
     */
    private function mint(string $stagingKey, string $mime, int $size, User $uploader, array $subject): array
    {
        $expiresAt = Carbon::now()->addMinutes(self::TTL_MINUTES);

        $signed = $this->storage->presignedUploadUrl($stagingKey, $mime, self::TTL_MINUTES);

        $uploadId = Crypt::encryptString(json_encode([
            ...$subject,
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

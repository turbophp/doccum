<?php

declare(strict_types=1);

use App\Actions\Files\StoreFileVersion;
use App\Enums\AccessLevel;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * item/api-presigned-upload (issue #24). This item's own doneWhen, verbatim:
 * "End-to-end url then commit; a size or checksum mismatch is rejected and
 * the staging object removed; an archived period is rejected at
 * upload-url; the sweep removes only stale staging objects." (the sweep
 * clause is tests/Feature/SweepUploadsTest.php).
 *
 * The end-to-end clause is the hard part this item's own brief flags:
 * Storage::fake() has no BUILT-IN seam for a presigned PUT the way it does
 * for a presigned GET, until buildTemporaryUploadUrlsUsing() is registered
 * here -- Laravel's own counterpart to buildTemporaryUrlsUsing(), read off
 * Illuminate\Filesystem\FilesystemAdapter's source rather than assumed (see
 * tests/Feature/DocumentStorageTest.php's own proof of the seam in
 * isolation). Registering it here makes step 2 of the flow -- "the client's
 * own direct PUT" -- a real write to the SAME faked disk DocumentStorage
 * itself resolves, at the exact key the presigned url names, which is
 * about as close to "a real client PUT bytes to MinIO" as a test without a
 * real S3-compatible server can get.
 */
beforeEach(function () {
    Storage::fake('documents');
    $this->seed(RolesAndPermissionsSeeder::class);

    Storage::disk('documents')->buildTemporaryUploadUrlsUsing(
        fn (string $path, DateTimeInterface $expires, array $options = []): array => [
            'url' => "https://minio.test/{$path}",
            'headers' => ['Content-Type' => $options['ContentType'] ?? ''],
        ],
    );

    $this->owner = User::factory()->create();
    $this->owner->assignRole('member');

    $this->dir = Directory::factory()->create();
    DirectoryGrant::create([
        'directory_id' => $this->dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $this->owner->id,
        'level' => AccessLevel::Edit,
    ]);
});

/**
 * Pulls the staging key back out of a minted upload_url -- the ONLY thing a
 * test needs from it, since the fake callback above names it verbatim
 * after "https://minio.test/". A real client never does this; it just PUTs
 * to the url it was given. This is the test's stand-in for that PUT.
 */
function stagingKeyFromUploadUrl(string $url): string
{
    return str_replace('https://minio.test/', '', $url);
}

function tempSource(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'doccum');
    file_put_contents($path, $contents);

    return $path;
}

// --- clause 1: end-to-end, url then commit -----------------------------------

it('goes end-to-end for a brand new file: mint a url, PUT bytes, then commit', function () {
    Sanctum::actingAs($this->owner, ['files:write']);

    $contents = 'end-to-end upload body';
    $checksum = hash('sha256', $contents);

    $mint = $this->postJson('/api/v1/files/upload-url', [
        'directory_id' => $this->dir->id,
        'name' => 'report.pdf',
        'mime' => 'application/pdf',
        'size' => strlen($contents),
    ])->assertCreated()->json('data');

    expect($mint)->toHaveKeys(['upload_id', 'upload_url', 'upload_headers', 'expires_at']);

    $stagingKey = stagingKeyFromUploadUrl($mint['upload_url']);

    // Step 2: the client's own direct PUT, simulated by writing straight to
    // the faked disk at the key the presigned url named -- see this file's
    // own docblock for why that is the closest a test gets to a real PUT.
    Storage::disk('documents')->put($stagingKey, $contents);

    $commit = $this->postJson('/api/v1/files', [
        'directory_id' => $this->dir->id,
        'upload_id' => $mint['upload_id'],
        'checksum' => $checksum,
    ])->assertCreated()->json('data');

    expect($commit['name'])->toBe('report.pdf')
        ->and($commit['checksum'])->toBe($checksum)
        ->and($commit['directory_id'])->toBe($this->dir->id);

    $file = File::findOrFail($commit['id']);

    expect(Storage::disk('documents')->get($file->currentVersion->object_key))->toBe($contents)
        // Nothing left in staging for the sweep to find once a commit
        // actually succeeds.
        ->and(Storage::disk('documents')->exists($stagingKey))->toBeFalse();
});

it('versions an existing file end-to-end through the file-scoped upload url', function () {
    $file = app(StoreFileVersion::class)->handle($this->owner, $this->dir, tempSource('v1 body'), 'a.txt');

    Sanctum::actingAs($this->owner, ['files:write']);

    $contents = 'v2 body, end-to-end';
    $checksum = hash('sha256', $contents);

    $mint = $this->postJson("/api/v1/files/{$file->id}/versions/upload-url", [
        'mime' => 'text/plain',
        'size' => strlen($contents),
    ])->assertCreated()->json('data');

    $stagingKey = stagingKeyFromUploadUrl($mint['upload_url']);
    Storage::disk('documents')->put($stagingKey, $contents);

    $commit = $this->postJson('/api/v1/files', [
        'file_id' => $file->id,
        'upload_id' => $mint['upload_id'],
        'checksum' => $checksum,
    ])->assertCreated()->json('data');

    expect($commit['id'])->toBe($file->id)
        ->and($commit['name'])->toBe('a.txt') // never renamed by the client's own upload
        ->and($commit['checksum'])->toBe($checksum);

    expect(File::count())->toBe(1)
        ->and($file->fresh()->versions()->count())->toBe(2)
        ->and(Storage::disk('documents')->exists($stagingKey))->toBeFalse();
});

// --- clause 2: a size or checksum mismatch is rejected AND the staging object removed ---

it('rejects a commit whose staged object size does not match what was declared, and removes it', function () {
    Sanctum::actingAs($this->owner, ['files:write']);

    $mint = $this->postJson('/api/v1/files/upload-url', [
        'directory_id' => $this->dir->id,
        'name' => 'a.txt',
        'mime' => 'text/plain',
        'size' => 100, // declared size the PUT below will not match
    ])->assertCreated()->json('data');

    $stagingKey = stagingKeyFromUploadUrl($mint['upload_url']);
    Storage::disk('documents')->put($stagingKey, 'only nine'); // 9 bytes, not 100

    $this->postJson('/api/v1/files', [
        'directory_id' => $this->dir->id,
        'upload_id' => $mint['upload_id'],
        'checksum' => hash('sha256', 'only nine'),
    ])->assertUnprocessable();

    expect(File::count())->toBe(0)
        // The rejection alone is half of this clause -- the removal is the
        // other half, and a rejection that leaves the object behind is not
        // a pass.
        ->and(Storage::disk('documents')->exists($stagingKey))->toBeFalse();
});

it('rejects a commit whose staged bytes do not match the declared checksum, and removes it', function () {
    Sanctum::actingAs($this->owner, ['files:write']);

    $contents = 'genuine bytes';

    $mint = $this->postJson('/api/v1/files/upload-url', [
        'directory_id' => $this->dir->id,
        'name' => 'a.txt',
        'mime' => 'text/plain',
        'size' => strlen($contents),
    ])->assertCreated()->json('data');

    $stagingKey = stagingKeyFromUploadUrl($mint['upload_url']);
    Storage::disk('documents')->put($stagingKey, $contents); // right size, PUT correctly

    $this->postJson('/api/v1/files', [
        'directory_id' => $this->dir->id,
        'upload_id' => $mint['upload_id'],
        'checksum' => hash('sha256', 'a completely different body'), // declared wrong
    ])->assertUnprocessable();

    expect(File::count())->toBe(0)
        ->and(Storage::disk('documents')->exists($stagingKey))->toBeFalse();
});

// --- clause 3: an archived period is rejected at upload-url, not at commit ---

it('refuses to mint an upload url for a new file when the target period is archived', function () {
    Sanctum::actingAs($this->owner, ['files:write']);

    ArchivePeriod::factory()->create([
        'year' => (int) now()->year,
        'month' => (int) now()->month,
        'archived_at' => now(),
    ]);

    $this->postJson('/api/v1/files/upload-url', [
        'directory_id' => $this->dir->id,
        'name' => 'a.txt',
        'mime' => 'text/plain',
        'size' => 5,
    ])->assertUnprocessable();

    expect(File::count())->toBe(0);
});

it('refuses to mint a version upload url when the file\'s own period is archived', function () {
    $file = app(StoreFileVersion::class)->handle($this->owner, $this->dir, tempSource('v1'), 'a.txt');

    ArchivePeriod::factory()->create([
        'year' => $file->period_year,
        'month' => $file->period_month,
        'archived_at' => now(),
    ]);

    Sanctum::actingAs($this->owner, ['files:write']);

    $this->postJson("/api/v1/files/{$file->id}/versions/upload-url", [
        'mime' => 'text/plain',
        'size' => 5,
    ])->assertUnprocessable();

    expect($file->fresh()->versions()->count())->toBe(1);
});

// --- gate spot-checks, consistent with the rest of the /api/v1 surface -------

it('403s a token missing files:write for the upload-url route, despite the permission and ACL both being fine', function () {
    Sanctum::actingAs($this->owner, ['files:read']);

    $this->postJson('/api/v1/files/upload-url', [
        'directory_id' => $this->dir->id,
        'name' => 'a.txt',
        'mime' => 'text/plain',
        'size' => 5,
    ])->assertForbidden();
});

it('404s an upload-url request for a directory wholly outside the token owner\'s reach', function () {
    $outside = Directory::factory()->create();

    Sanctum::actingAs($this->owner, ['files:write']);

    $this->postJson('/api/v1/files/upload-url', [
        'directory_id' => $outside->id,
        'name' => 'a.txt',
        'mime' => 'text/plain',
        'size' => 5,
    ])->assertNotFound();
});

it('404s a version upload-url request for a file whose directory is wholly outside the token owner\'s reach', function () {
    $outsideFile = File::factory()->create();

    Sanctum::actingAs($this->owner, ['files:write']);

    $this->postJson("/api/v1/files/{$outsideFile->id}/versions/upload-url", [
        'mime' => 'text/plain',
        'size' => 5,
    ])->assertNotFound();
});

it('rejects a commit naming both directory_id and file_id', function () {
    $file = app(StoreFileVersion::class)->handle($this->owner, $this->dir, tempSource('v1'), 'a.txt');

    Sanctum::actingAs($this->owner, ['files:write']);

    $this->postJson('/api/v1/files', [
        'directory_id' => $this->dir->id,
        'file_id' => $file->id,
        'upload_id' => 'irrelevant',
        'checksum' => str_repeat('a', 64),
    ])->assertUnprocessable();
});

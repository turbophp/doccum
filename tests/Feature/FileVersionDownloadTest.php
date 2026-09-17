<?php

declare(strict_types=1);

/**
 * item/files-versions-replace (issue #102): a per-version download route,
 * modelled on FileDownloadTest.php's setup. authorize('download', $file)
 * covers the reach question (routes through view() -> liveDirectory(), the
 * same as the current-version download), so what is new and load-bearing
 * here is FileVersionDownloadController's own abort_if(): a version id that
 * exists but belongs to SOME OTHER file must 404, never redirect to that
 * other file's object. Without it, {version} alone -- with no directory_id
 * of its own to check -- would let anyone who can view ANY file download
 * the bytes of ANY version in the instance merely by guessing its id.
 */

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    Storage::disk('documents')->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $expires): string => "https://minio.test/{$path}?e={$expires->getTimestamp()}",
    );

    $this->seed(RolesAndPermissionsSeeder::class);
    User::factory()->create(); // instance is set up

    $this->dirA = Directory::factory()->create();
    $this->fileA = File::factory()->for($this->dirA, 'directory')->create();
    $this->versionA1 = FileVersion::factory()->for($this->fileA)->create(['version_number' => 1]);
    $this->versionA2 = FileVersion::factory()->for($this->fileA)->create(['version_number' => 2]);
    $this->fileA->update(['current_version_id' => $this->versionA2->id]);

    $this->dirB = Directory::factory()->create();
    $this->fileB = File::factory()->for($this->dirB, 'directory')->create();
    $this->versionB1 = FileVersion::factory()->for($this->fileB)->create(['version_number' => 1]);
    $this->fileB->update(['current_version_id' => $this->versionB1->id]);
});

// Named grantOn() rather than allow(): FileDownloadTest.php already declares
// a top-level allow() function, and Pest test files share one global PHP
// namespace, so declaring both under the same name fatals with "Cannot
// redeclare function allow()".
function grantOn(Directory $dir, User $user, AccessLevel $level): void
{
    DirectoryGrant::create([
        'directory_id' => $dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $level,
    ]);
}

it('redirects an authorised user to a presigned url for an old version', function () {
    $user = User::factory()->create();
    grantOn($this->dirA, $user, AccessLevel::View);

    $this->actingAs($user)
        ->get(route('files.versions.download', [$this->fileA, $this->versionA1]))
        ->assertRedirectContains('https://minio.test/'.$this->versionA1->object_key);
});

it('404s a version id belonging to a different file', function () {
    // The caller can view file A but NOT file B -- exactly the cross-file
    // guess this controller's abort_if() exists to close. This is the
    // expectFailing target for the file-version-download-cross-file-404
    // mutation entry: delete the abort_if() and this passes with the
    // redirect target unchanged, which is exactly what must not happen.
    $user = User::factory()->create();
    grantOn($this->dirA, $user, AccessLevel::View);

    $response = $this->actingAs($user)
        ->get(route('files.versions.download', [$this->fileA, $this->versionB1]));

    $response->assertNotFound();
    expect($response->headers->get('Location'))->not->toContain($this->versionB1->object_key);
});

it('404s a cross-file version id even when the caller can view both files', function () {
    $user = User::factory()->create();
    grantOn($this->dirA, $user, AccessLevel::View);
    grantOn($this->dirB, $user, AccessLevel::View);

    $this->actingAs($user)
        ->get(route('files.versions.download', [$this->fileA, $this->versionB1]))
        ->assertNotFound();
});

it('403s an unauthorised caller regardless of whether the version id exists', function () {
    $stranger = User::factory()->create();

    // A version id that genuinely exists (on a file the caller cannot view)
    // and one that does not exist at all must answer identically: 403 either
    // way. Route model binding resolves {file} before the controller runs,
    // so a caller who cannot view $fileA never even reaches the version
    // lookup -- there is no existence oracle here.
    $this->actingAs($stranger)
        ->get(route('files.versions.download', [$this->fileA, $this->versionA1]))
        ->assertForbidden();

    $this->actingAs($stranger)
        ->get(route('files.versions.download', [$this->fileA, 999999]))
        ->assertForbidden();
});

it('redirects a guest to login', function () {
    $this->get(route('files.versions.download', [$this->fileA, $this->versionA1]))
        ->assertRedirect(route('login'));
});

it('404s a trashed file', function () {
    $user = User::factory()->create();
    grantOn($this->dirA, $user, AccessLevel::View);
    $this->fileA->delete();

    $this->actingAs($user)
        ->get(route('files.versions.download', [$this->fileA, $this->versionA1]))
        ->assertNotFound();
});

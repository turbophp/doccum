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
use Illuminate\Support\Facades\Log;
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

    // Belt and braces alongside assertNotFound(): whatever the status, the
    // response must not carry a signed URL for a version of a file this
    // caller cannot reach.
    //
    // str_contains() rather than expect()->not->toContain(): this Pest
    // restricts toContain() to iterables, so on a header string it raises
    // InvalidExpectationValue and the assertion never actually runs -- a
    // test that errors instead of checking, which is the same failure mode
    // CLAUDE.md warns about for toThrow() on an interface.
    expect(str_contains(
        (string) $response->headers->get('Location'),
        (string) $this->versionB1->object_key,
    ))->toBeFalse();
});

it('404s a cross-file version id even when the caller can view both files', function () {
    $user = User::factory()->create();
    grantOn($this->dirA, $user, AccessLevel::View);
    grantOn($this->dirB, $user, AccessLevel::View);

    $this->actingAs($user)
        ->get(route('files.versions.download', [$this->fileA, $this->versionB1]))
        ->assertNotFound();
});

it('answers an unauthorised caller the same whatever the version id', function () {
    $stranger = User::factory()->create();

    // THE PROPERTY IS UNCHANGED AND THE STATUS IS NOT. A version id that
    // genuinely exists on a file the caller cannot view, and one that does
    // not exist at all, must answer IDENTICALLY -- that is what closes the
    // oracle, and which status the two share is local (item's own wording).
    //
    // It used to be 403 for both, and the comment here explained why: the
    // caller could not view $fileA, so authorize() refused before the
    // version lookup was ever reached. That reasoning was correct about the
    // VERSION id and hid a second oracle over the FILE id -- implicit
    // binding resolved {file} before authorize(), so an unreachable file
    // 403'd where a nonexistent one 404'd (decision/0112, which records
    // that this very claim was half true and load-bearing).
    //
    // {file} is now an int resolved through viewableFileOrFail(), so the
    // refusal for an unreachable file happens at the lookup and the shared
    // answer is 404. Both halves still agree, which is the requirement.
    $this->actingAs($stranger)
        ->get(route('files.versions.download', [$this->fileA, $this->versionA1]))
        ->assertNotFound();

    $this->actingAs($stranger)
        ->get(route('files.versions.download', [$this->fileA, 999999]))
        ->assertNotFound();
});

it('answers an unreachable file the same as a nonexistent one, for a version download', function () {
    // item/reach-oracle-route-binding, on the {file} parameter specifically.
    // The pair above varies the VERSION id against one unreachable file;
    // this one varies the FILE id, which is the parameter this item closes.
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('files.versions.download', [$this->fileA, $this->versionA1]))
        ->assertNotFound();

    $this->actingAs($stranger)
        ->get(route('files.versions.download', [((int) File::query()->max('id')) + 1, $this->versionA1]))
        ->assertNotFound();
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

/**
 * item/download-missing-object (issue #134), the per-version route half of
 * FileDownloadTest.php's coverage. The row for $this->versionA1 exists;
 * only the object behind it is gone. See that test's docblock for why the
 * fix has to open the stream before the response is built rather than
 * catch whatever RuntimeException falls out of streamDownload()'s callback.
 */
it('answers 502 with a logged object key when a specific version\'s object is missing from storage', function () {
    config(['filesystems.disks.documents.endpoint' => 'http://127.0.0.1:9000']);

    // The object existed and is deleted out from under the still-live row --
    // see FileDownloadTest.php's matching case for why that, rather than
    // simply never writing it, is what the operator-restore mistake
    // actually looks like.
    Storage::disk('documents')->put($this->versionA1->object_key, 'the bytes themselves');
    Storage::disk('documents')->delete($this->versionA1->object_key);

    // Log::spy(), not Log::shouldReceive(): a facade set up with
    // shouldReceive() is a STRICT Mockery mock, so any OTHER Log:: call
    // anywhere in this request -- a deprecation, a framework notice, a
    // channel this test knows nothing about -- raises BadMethodCallException
    // and reddens the test for a reason that has nothing to do with what it
    // asserts. A spy permits every call and is asked afterwards about the one
    // that matters, which is the only thing this test is claiming. There is
    // no existing Log:: assertion in this suite to copy, so this is the
    // convention rather than a departure from one.
    Log::spy();

    $version = $this->versionA1;

    $user = User::factory()->create();
    grantOn($this->dirA, $user, AccessLevel::View);

    $response = $this->actingAs($user)
        ->get(route('files.versions.download', [$this->fileA, $this->versionA1]));

    $response->assertStatus(502);
    expect($response->getContent())->toContain($this->versionA1->object_key);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($version): bool {
            return $message === 'Download failed: object missing from storage.'
                && $context['object_key'] === $version->object_key
                && $context['file_version_id'] === $version->id;
        });
});

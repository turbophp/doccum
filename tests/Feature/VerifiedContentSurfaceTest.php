<?php

declare(strict_types=1);

use App\Actions\Directories\BuildDirectoryArchive;
use App\Enums\AccessLevel;
use App\Enums\ArchiveStatus;
use App\Models\Directory;
use App\Models\DirectoryArchive;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * item/email-verification-decided (issue #161), the half the original brief
 * missed: verification is only DECIDED and ENFORCED if it actually gates
 * the content surface, not just one dashboard tile and two settings pages.
 * routes/web.php now puts `files.browse`, the three download routes,
 * `search`, `trash`, and all five `admin.*` routes inside the SAME
 * Route::middleware(['auth', 'verified'])->group(...) block -- `admin.*`
 * ALONGSIDE their existing `can:` middleware, not instead of it.
 *
 * This is deliberately the single place that proves the whole group, rather
 * than one assertion scattered into ten different destination test files:
 * every route below is checked against ONE administrator who is given every
 * permission there is (RolesAndPermissionsSeeder's `admin` role) and made
 * UNVERIFIED, so a `can:` middleware failure can never be mistaken for the
 * `verified` one -- if this account, of all accounts, is turned back, it
 * can only be `verified` that turned it back. The sibling test right below
 * proves the fixtures themselves are real: the same account, once verified,
 * actually reaches every one of these routes -- so the redirect the first
 * test measures is attributable to `verified` alone, never to a fixture
 * that would have 404'd or 403'd regardless.
 *
 * NOT covered here, on purpose: routes/settings.php's `auth`-only group
 * (profile.edit) and the dashboard (already covered by
 * tests/Feature/Auth/EmailVerificationTest.php). See both files' own
 * comments at their respective splits for why profile stays reachable
 * unverified.
 */
beforeEach(function () {
    Storage::fake('documents');
    // The two download routes redirect to a presigned URL when reachable
    // (see App\Http\Controllers\FileDownloadController::servesPresignedUrls()) --
    // Storage::fake()'s local driver does not implement temporaryUrl() at
    // all otherwise. Modelled exactly on tests/Feature/FileDownloadTest.php's
    // own setup.
    Storage::disk('documents')->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $expires): string => "https://minio.test/{$path}?e={$expires->getTimestamp()}",
    );

    $this->seed(RolesAndPermissionsSeeder::class);

    $this->dir = Directory::factory()->create();
    $this->file = File::factory()->for($this->dir, 'directory')->create();
    $this->version = FileVersion::factory()->for($this->file)->create(['version_number' => 1]);
    $this->file->update(['current_version_id' => $this->version->id]);

    // BuildDirectoryArchive::handle() -- unlike a plain download, which
    // redirects to a presigned URL without ever touching the disk when
    // servesPresignedUrls() is true (see tests/Feature/FileDownloadTest.php) --
    // reads real bytes to zip them, so the fake disk needs an actual object
    // at this key, not merely a database row that claims one exists.
    Storage::disk('documents')->put($this->version->object_key, 'verified-content-surface-test');
});

/**
 * One administrator, granted Manage on $dir and every permission the
 * seeder knows, with a built directory archive ready to hand over --
 * everything every route in the group needs to succeed for SOME viewer.
 */
function contentSurfaceAdmin(User $user, Directory $dir): DirectoryArchive
{
    $user->assignRole('admin');
    grant($dir, $user, AccessLevel::Manage);

    $archive = DirectoryArchive::create([
        'directory_id' => $dir->id,
        'requested_by' => $user->id,
        'status' => ArchiveStatus::Pending,
    ]);
    app(BuildDirectoryArchive::class)->handle($archive);

    return $archive;
}

/**
 * @return array<string, string>
 */
function contentSurfaceRoutes(File $file, FileVersion $version, DirectoryArchive $archive): array
{
    return [
        'files.browse' => route('files.browse'),
        'search' => route('search'),
        'trash' => route('trash'),
        'admin.properties' => route('admin.properties'),
        'admin.users' => route('admin.users'),
        'admin.roles' => route('admin.roles'),
        'admin.periods' => route('admin.periods'),
        'admin.settings' => route('admin.settings'),
        'files.download' => route('files.download', $file),
        'files.versions.download' => route('files.versions.download', [$file, $version]),
        'directories.archives.download' => route('directories.archives.download', $archive),
    ];
}

it('redirects an unverified administrator -- holding every permission there is -- away from every route in the content-surface verified group', function () {
    $admin = User::factory()->unverified()->create();
    $archive = contentSurfaceAdmin($admin, $this->dir);

    foreach (contentSurfaceRoutes($this->file, $this->version, $archive) as $name => $url) {
        $this->actingAs($admin)
            ->get($url)
            ->assertRedirect(route('verification.notice', absolute: false));
    }
});

// The download routes redirect to a presigned URL on SUCCESS too, so they
// cannot share the generic "assertOk()" this test uses for every other
// route in the group -- they are asserted separately, against the fake
// presigned host configured above, specifically so a redirect to THAT host
// is never confused with a redirect to the verification notice.
it('lets a verified administrator holding every permission reach every ordinary route in the same group', function () {
    $admin = User::factory()->create();
    $archive = contentSurfaceAdmin($admin, $this->dir);

    $routes = contentSurfaceRoutes($this->file, $this->version, $archive);
    unset($routes['files.download'], $routes['files.versions.download']);

    foreach ($routes as $name => $url) {
        $this->actingAs($admin)->get($url)->assertOk();
    }
});

it('lets a verified administrator download a file and a specific version, not merely reach a route', function () {
    $admin = User::factory()->create();
    contentSurfaceAdmin($admin, $this->dir);

    $this->actingAs($admin)
        ->get(route('files.download', $this->file))
        ->assertRedirectContains('https://minio.test/'.$this->version->object_key);

    $this->actingAs($admin)
        ->get(route('files.versions.download', [$this->file, $this->version]))
        ->assertRedirectContains('https://minio.test/'.$this->version->object_key);
});

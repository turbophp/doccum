<?php

declare(strict_types=1);

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
    $this->dir = Directory::factory()->create();
    $this->file = File::factory()->for($this->dir, 'directory')->create();
    $this->version = FileVersion::factory()->for($this->file)->create(['version_number' => 1]);
    $this->file->update(['current_version_id' => $this->version->id]);
});

function allow(Directory $dir, User $user, AccessLevel $level): void
{
    DirectoryGrant::create([
        'directory_id' => $dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $level,
    ]);
}

it('redirects an authorised user to a presigned url', function () {
    $user = User::factory()->create();
    allow($this->dir, $user, AccessLevel::View);

    $this->actingAs($user)
        ->get(route('files.download', $this->file))
        ->assertRedirectContains('https://minio.test/'.$this->version->object_key);
});

it('forbids a user without a grant', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('files.download', $this->file))
        ->assertForbidden();
});

it('redirects a guest to login', function () {
    $this->get(route('files.download', $this->file))->assertRedirect(route('login'));
});

it('404s a file with no current version', function () {
    $user = User::factory()->create();
    allow($this->dir, $user, AccessLevel::View);
    $this->file->update(['current_version_id' => null]);

    $this->actingAs($user)->get(route('files.download', $this->file))->assertNotFound();
});

it('does not expose a trashed file', function () {
    $user = User::factory()->create();
    allow($this->dir, $user, AccessLevel::View);
    $this->file->delete();

    $this->actingAs($user)->get(route('files.download', $this->file))->assertNotFound();
});

/**
 * item/download-reaches-the-browser (issue #74). The presigned redirect is
 * spec 6's design and is kept wherever it can work; these cover the case
 * where it cannot, which is the shipped single container.
 */
it('streams the bytes itself when the storage endpoint is one the browser cannot reach', function () {
    config(['filesystems.disks.documents.endpoint' => 'http://127.0.0.1:9000']);
    Storage::disk('documents')->put($this->version->object_key, 'the bytes themselves');

    $user = User::factory()->create();
    allow($this->dir, $user, AccessLevel::View);

    $response = $this->actingAs($user)->get(route('files.download', $this->file));

    $response->assertOk();
    expect($response->streamedContent())->toBe('the bytes themselves');
});

it('still redirects to a presigned url when the endpoint is publicly reachable', function () {
    config(['filesystems.disks.documents.endpoint' => 'https://objects.example.test']);

    $user = User::factory()->create();
    allow($this->dir, $user, AccessLevel::View);

    $this->actingAs($user)
        ->get(route('files.download', $this->file))
        ->assertRedirectContains('https://minio.test/'.$this->version->object_key);
});

it('redirects when no endpoint is configured at all, which is plain AWS S3', function () {
    config(['filesystems.disks.documents.endpoint' => null]);

    $user = User::factory()->create();
    allow($this->dir, $user, AccessLevel::View);

    $this->actingAs($user)
        ->get(route('files.download', $this->file))
        ->assertRedirectContains('https://minio.test/'.$this->version->object_key);
});

/**
 * item/download-missing-object (issue #134). The row and its current
 * version both exist; only the object behind them is gone, e.g. `/data` was
 * restored without `objects/`. Before this fix, readStream() threw a bare
 * RuntimeException from inside the streamDownload() callback -- run by
 * Symfony during sendContent(), after the status line had already gone out
 * -- so the resulting status was an accident of the server rather than a
 * decision. The fix opens the stream before the response is built, so the
 * missing object is caught and answered before any header commits.
 */
it('answers 502 with a logged object key when the current version\'s object is missing from storage', function () {
    config(['filesystems.disks.documents.endpoint' => 'http://127.0.0.1:9000']);

    // The object existed and is deleted out from under the still-live row --
    // the operator-restore-without-objects scenario issue #134 describes --
    // rather than simply never having been written, so the fake disk exactly
    // mirrors what a partial `/data` restore leaves behind.
    Storage::disk('documents')->put($this->version->object_key, 'the bytes themselves');
    Storage::disk('documents')->delete($this->version->object_key);

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

    $version = $this->version;

    $user = User::factory()->create();
    allow($this->dir, $user, AccessLevel::View);

    $response = $this->actingAs($user)->get(route('files.download', $this->file));

    $response->assertStatus(502);
    expect($response->getContent())->toContain($this->version->object_key);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($version): bool {
            return $message === 'Download failed: object missing from storage.'
                && $context['object_key'] === $version->object_key
                && $context['file_version_id'] === $version->id;
        });
});

it('treats localhost and 0.0.0.0 as unreachable too, not just 127.0.0.1', function () {
    Storage::disk('documents')->put($this->version->object_key, 'unreachable host bytes');

    $user = User::factory()->create();
    allow($this->dir, $user, AccessLevel::View);

    foreach (['http://localhost:9000', 'http://0.0.0.0:9000', 'http://LOCALHOST:9000'] as $endpoint) {
        config(['filesystems.disks.documents.endpoint' => $endpoint]);

        $response = $this->actingAs($user)->get(route('files.download', $this->file));

        expect($response->getStatusCode())->toBe(200, "endpoint {$endpoint} should have streamed");
    }
});

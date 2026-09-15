<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    Storage::disk('documents')->buildTemporaryUrlsUsing(
        fn (string $path, DateTimeInterface $expires): string => "https://minio.test/{$path}?e={$expires->getTimestamp()}",
    );

    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);
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

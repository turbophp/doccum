<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->dir = Directory::factory()->create();
    $this->user = User::factory()->create();
    $this->user->assignRole('member');
});

// Named giveAccess() rather than give(): DirectoryPolicyTest.php already
// declares a top-level give() helper, and Pest test files share one global
// PHP namespace, so declaring both under the same name fatals with
// "Cannot redeclare function give()".
function giveAccess(Directory $dir, User $user, AccessLevel $level): void
{
    DirectoryGrant::create([
        'directory_id' => $dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $level,
    ]);
}

it('requires edit to upload into a directory', function () {
    giveAccess($this->dir, $this->user, AccessLevel::View);
    expect($this->user->can('create', [File::class, $this->dir]))->toBeFalse();

    DirectoryGrant::query()->delete();
    giveAccess($this->dir, $this->user, AccessLevel::Edit);
    expect($this->user->can('create', [File::class, $this->dir]))->toBeTrue();
});

it('requires the capability as well as the level', function () {
    // A user with manage on the directory but no files.upload capability.
    $stranger = User::factory()->create();
    giveAccess($this->dir, $stranger, AccessLevel::Manage);

    expect($stranger->can('create', [File::class, $this->dir]))->toBeFalse();
});

it('lets a file inherit its directory access', function () {
    $file = File::factory()->for($this->dir, 'directory')->create();
    expect($this->user->can('view', $file))->toBeFalse();

    giveAccess($this->dir, $this->user, AccessLevel::View);
    expect($this->user->fresh()->can('view', $file))->toBeTrue();
});

it('requires edit to rename a file', function () {
    $file = File::factory()->for($this->dir, 'directory')->create();
    giveAccess($this->dir, $this->user, AccessLevel::View);
    expect($this->user->fresh()->can('update', $file))->toBeFalse();

    DirectoryGrant::query()->delete();
    giveAccess($this->dir, $this->user, AccessLevel::Edit);
    expect($this->user->fresh()->can('update', $file))->toBeTrue();
});

it('requires files.delete as well as edit to trash a file', function () {
    // $this->user carries files.delete via the member role, but has no
    // grant on the directory at all.
    $file = File::factory()->for($this->dir, 'directory')->create();
    expect($this->user->can('delete', $file))->toBeFalse();

    giveAccess($this->dir, $this->user, AccessLevel::Edit);
    expect($this->user->fresh()->can('delete', $file))->toBeTrue();
});

it('requires edit as well as files.delete to trash a file', function () {
    $stranger = User::factory()->create();
    giveAccess($this->dir, $stranger, AccessLevel::Edit);
    $file = File::factory()->for($this->dir, 'directory')->create();

    expect($stranger->can('delete', $file))->toBeFalse();
});

it('requires files.restore as well as edit to restore a file', function () {
    $file = File::factory()->for($this->dir, 'directory')->create();
    expect($this->user->can('restore', $file))->toBeFalse();

    giveAccess($this->dir, $this->user, AccessLevel::Edit);
    expect($this->user->fresh()->can('restore', $file))->toBeTrue();
});

it('requires edit as well as files.restore to restore a file', function () {
    $stranger = User::factory()->create();
    giveAccess($this->dir, $stranger, AccessLevel::Edit);
    $file = File::factory()->for($this->dir, 'directory')->create();

    expect($stranger->can('restore', $file))->toBeFalse();
});

it('requires edit on the destination as well as the source to move a file', function () {
    $other = Directory::factory()->create();
    $file = File::factory()->for($this->dir, 'directory')->create();

    giveAccess($this->dir, $this->user, AccessLevel::Edit);
    expect($this->user->fresh()->can('move', [$file, $other]))->toBeFalse();

    giveAccess($other, $this->user, AccessLevel::Edit);
    expect($this->user->fresh()->can('move', [$file, $other]))->toBeTrue();
});

it('requires edit on the source as well as the destination to move a file', function () {
    $other = Directory::factory()->create();
    $file = File::factory()->for($this->dir, 'directory')->create();

    giveAccess($other, $this->user, AccessLevel::Edit);
    expect($this->user->fresh()->can('move', [$file, $other]))->toBeFalse();
});

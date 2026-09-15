<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;

beforeEach(function () {
    $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class);
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

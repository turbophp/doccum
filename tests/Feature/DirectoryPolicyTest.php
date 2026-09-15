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

function give(Directory $dir, User $user, AccessLevel $level): void
{
    DirectoryGrant::create([
        'directory_id' => $dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $level,
    ]);
}

it('denies viewing without a grant', function () {
    expect($this->user->can('view', $this->dir))->toBeFalse();
});

it('allows viewing with a view grant', function () {
    give($this->dir, $this->user, AccessLevel::View);

    expect($this->user->can('view', $this->dir))->toBeTrue();
});

it('requires manage to grant access', function () {
    give($this->dir, $this->user, AccessLevel::Edit);
    expect($this->user->can('manageAccess', $this->dir))->toBeFalse();

    DirectoryGrant::query()->delete();
    give($this->dir, $this->user, AccessLevel::Manage);
    expect($this->user->can('manageAccess', $this->dir))->toBeTrue();
});

it('lets an admin reach anything', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $file = File::factory()->for($this->dir, 'directory')->create();

    expect($admin->can('view', $this->dir))->toBeTrue()
        ->and($admin->can('view', $file))->toBeTrue()
        ->and($admin->can('manageAccess', $this->dir))->toBeTrue();
});

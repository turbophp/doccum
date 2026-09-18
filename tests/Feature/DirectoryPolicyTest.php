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

/**
 * Rewritten, not weakened: its second assertion used to require that a plain
 * member holding a Manage GRANT passes manageAccess(), which documented the
 * ability as it shipped rather than the rule CLAUDE.md states -- two layers,
 * both must pass. Spec §5 groups "grant/revoke access, move or delete the
 * directory itself" as one capability, and move()/delete()/restore() all
 * require directories.manage; only this one did not.
 *
 * That mattered because MEMBER_PERMISSIONS carries no directories.manage and
 * every user holds manage on their own home directory by ordinary grant, so
 * every member could hand out any level on their own subtree. Nothing called
 * the ability from the UI, so it was never reachable -- item/directory-access-ui
 * is what would have made it live.
 *
 * Both layers are now asserted independently, so neither can be dropped
 * without a failure: the grant alone is refused, the permission alone is
 * refused, and only the pair passes.
 */
it('requires BOTH directories.manage and a manage-level grant to grant access', function () {
    // Level alone: a member with Manage on the directory but no permission.
    give($this->dir, $this->user, AccessLevel::Manage);
    expect($this->user->can('manageAccess', $this->dir))->toBeFalse();

    // Permission alone: directories.manage granted DIRECTLY to a member,
    // deliberately not via the admin role. An admin holds
    // directories.view-all, and DirectoryAccess::resolve() short-circuits
    // that to AccessLevel::Manage on every directory -- so an admin fixture
    // here would pass the level check by bypass and prove nothing about the
    // grant, which is the trap this comment exists to stop the next person
    // walking into. givePermissionTo() is how the rest of this file does it.
    DirectoryGrant::query()->delete();
    $holder = User::factory()->create();
    $holder->assignRole('member');
    $holder->givePermissionTo('directories.manage');
    give($this->dir, $holder, AccessLevel::Edit);
    expect($holder->fresh()->can('manageAccess', $this->dir))->toBeFalse();

    // Both.
    DirectoryGrant::query()->delete();
    give($this->dir, $holder, AccessLevel::Manage);
    expect($holder->fresh()->can('manageAccess', $this->dir))->toBeTrue();
});

it('lets an admin reach anything', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $file = File::factory()->for($this->dir, 'directory')->create();

    expect($admin->can('view', $this->dir))->toBeTrue()
        ->and($admin->can('view', $file))->toBeTrue()
        ->and($admin->can('manageAccess', $this->dir))->toBeTrue();
});

it('requires edit to rename a directory', function () {
    give($this->dir, $this->user, AccessLevel::View);
    expect($this->user->fresh()->can('update', $this->dir))->toBeFalse();

    DirectoryGrant::query()->delete();
    give($this->dir, $this->user, AccessLevel::Edit);
    expect($this->user->fresh()->can('update', $this->dir))->toBeTrue();
});

it('requires directories.manage to move a directory', function () {
    // Destination null (a move to the root) so only the permission and the
    // access level on the directory being moved are in play.
    give($this->dir, $this->user, AccessLevel::Manage);

    expect($this->user->can('move', [$this->dir, null]))->toBeFalse();

    $this->user->givePermissionTo('directories.manage');
    expect($this->user->fresh()->can('move', [$this->dir, null]))->toBeTrue();
});

it('requires manage access on the directory as well as directories.manage to move it', function () {
    $this->user->givePermissionTo('directories.manage');

    expect($this->user->can('move', [$this->dir, null]))->toBeFalse();

    give($this->dir, $this->user, AccessLevel::Manage);
    expect($this->user->fresh()->can('move', [$this->dir, null]))->toBeTrue();
});

it('requires at least edit on the destination to move a directory into it', function () {
    $other = Directory::factory()->create();
    $this->user->givePermissionTo('directories.manage');
    give($this->dir, $this->user, AccessLevel::Manage);

    expect($this->user->fresh()->can('move', [$this->dir, $other]))->toBeFalse();

    give($other, $this->user, AccessLevel::Edit);
    expect($this->user->fresh()->can('move', [$this->dir, $other]))->toBeTrue();
});

it('allows moving to the root with no destination to check', function () {
    $this->user->givePermissionTo('directories.manage');
    give($this->dir, $this->user, AccessLevel::Manage);

    expect($this->user->fresh()->can('move', [$this->dir, null]))->toBeTrue();
});

it('requires directories.manage to trash a directory (delete() gates it), manage access alone is not enough', function () {
    give($this->dir, $this->user, AccessLevel::Manage);

    expect($this->user->can('delete', $this->dir))->toBeFalse();

    $this->user->givePermissionTo('directories.manage');
    expect($this->user->fresh()->can('delete', $this->dir))->toBeTrue();
});

it('requires manage access to trash a directory, directories.manage alone is not enough', function () {
    $this->user->givePermissionTo('directories.manage');

    expect($this->user->can('delete', $this->dir))->toBeFalse();

    give($this->dir, $this->user, AccessLevel::Manage);
    expect($this->user->fresh()->can('delete', $this->dir))->toBeTrue();
});

it('requires directories.manage to restore a directory, manage access alone is not enough', function () {
    give($this->dir, $this->user, AccessLevel::Manage);

    expect($this->user->can('restore', $this->dir))->toBeFalse();

    $this->user->givePermissionTo('directories.manage');
    expect($this->user->fresh()->can('restore', $this->dir))->toBeTrue();
});

it('requires manage access to restore a directory, directories.manage alone is not enough', function () {
    $this->user->givePermissionTo('directories.manage');

    expect($this->user->can('restore', $this->dir))->toBeFalse();

    give($this->dir, $this->user, AccessLevel::Manage);
    expect($this->user->fresh()->can('restore', $this->dir))->toBeTrue();
});

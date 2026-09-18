<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('seeds the admin and member roles', function () {
    expect(Role::pluck('name')->all())->toContain('admin', 'member');
});

it('gives admin every permission', function () {
    $admin = Role::findByName('admin');

    expect($admin->permissions->pluck('name')->all())
        ->toContain('users.manage', 'directories.view-all', 'periods.manage', 'files.delete');
});

it('gives member only day-to-day capabilities', function () {
    $names = Role::findByName('member')->permissions->pluck('name')->all();

    expect($names)->toContain('files.upload', 'directories.create')
        ->and($names)->not->toContain('users.manage')
        ->and($names)->not->toContain('directories.view-all')
        ->and($names)->not->toContain('periods.manage');
});

it('lets a user hold a role and its permissions', function () {
    $user = User::factory()->create();
    $user->assignRole('member');

    expect($user->can('files.upload'))->toBeTrue()
        ->and($user->can('users.manage'))->toBeFalse();
});

it('is idempotent when seeded twice', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Role::where('name', 'admin')->count())->toBe(1);
});

// issue #214: docker/entrypoint.d/51-doccum-roles.sh runs this seeder (via
// App\Console\Commands\EnsureRoles) on EVERY container boot. An operator's
// edit through Settings -> Roles (App\Actions\Roles\SetRolePermissions) must
// still be there after the next one. Before this fix, the seeder ended in
// syncPermissions() on both roles, which replaced whatever was there --
// deleting this test's revokePermissionTo()/givePermissionTo() calls and
// re-running would make every assertion below fail, because the second seed
// would put both roles straight back to the constant lists.
it('leaves an operator-edited role alone when re-seeded, both roles already existing', function () {
    $member = Role::findByName('member');
    $member->givePermissionTo('periods.manage'); // operator grant
    $member->revokePermissionTo('files.upload'); // operator revoke

    $admin = Role::findByName('admin');
    $admin->revokePermissionTo('periods.manage'); // operator revoke, even on `admin`

    $this->seed(RolesAndPermissionsSeeder::class); // the next container boot

    $memberNames = $member->fresh()->permissions->pluck('name')->all();
    expect($memberNames)->toContain('periods.manage')
        ->and($memberNames)->not->toContain('files.upload');

    expect($admin->fresh()->hasPermissionTo('periods.manage'))->toBeFalse();
});

// The other half of the same decision: a role this run CREATES for the first
// time still gets the full default set, because nothing has had a chance to
// edit a role that did not exist a moment ago.
it('still grants the default set to a role created for the first time', function () {
    Role::where('name', 'member')->delete();

    $this->seed(RolesAndPermissionsSeeder::class);

    $names = Role::findByName('member')->permissions->pluck('name')->sort()->values()->all();
    expect($names)->toEqual(collect(RolesAndPermissionsSeeder::MEMBER_PERMISSIONS)->sort()->values()->all());
});

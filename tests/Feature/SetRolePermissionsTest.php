<?php

declare(strict_types=1);

use App\Actions\Roles\SetRolePermissions;
use App\Exceptions\LastAdministratorMustRemain;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('refuses to strip users.manage from a role that is its sole holder', function () {
    $admin = Role::findByName('admin');
    $solo = User::factory()->create();
    $solo->assignRole('admin');

    $withoutUsersManage = array_values(array_diff(RolesAndPermissionsSeeder::PERMISSIONS, ['users.manage']));

    expect(fn () => app(SetRolePermissions::class)->handle($admin, $withoutUsersManage))
        ->toThrow(LastAdministratorMustRemain::class);

    expect($admin->fresh()->hasPermissionTo('users.manage'))->toBeTrue()
        ->and($solo->fresh()->can('users.manage'))->toBeTrue();
});

it('permits stripping users.manage from a role when another holder exists outside that role', function () {
    $admin = Role::findByName('admin');
    $solo = User::factory()->create();
    $solo->assignRole('admin');

    // A DIRECT grant, not through any role -- proves the "someone else
    // still holds it" check looks past roles entirely, the same as
    // App\Support\LastAdministrator::wouldBeLostByDeleting() does for a
    // deleted user.
    $other = User::factory()->create();
    $other->givePermissionTo('users.manage');

    $withoutUsersManage = array_values(array_diff(RolesAndPermissionsSeeder::PERMISSIONS, ['users.manage']));

    app(SetRolePermissions::class)->handle($admin, $withoutUsersManage);

    expect($admin->fresh()->hasPermissionTo('users.manage'))->toBeFalse()
        ->and($other->fresh()->can('users.manage'))->toBeTrue()
        ->and($solo->fresh()->can('users.manage'))->toBeFalse();
});

it('permits removing users.manage from a role when nobody currently holds it', function () {
    // Nobody has ever been assigned the admin role in this test, so nobody
    // in the instance holds users.manage before this call -- the same
    // load-bearing early return App\Support\LastAdministrator::
    // wouldBeLostByDeleting() has, exercised here on the role side: without
    // it, an instance where the permission is unheld would refuse every
    // role edit forever, with no UI path left to grant it back to anyone.
    $admin = Role::findByName('admin');

    $withoutUsersManage = array_values(array_diff(RolesAndPermissionsSeeder::PERMISSIONS, ['users.manage']));

    app(SetRolePermissions::class)->handle($admin, $withoutUsersManage);

    expect($admin->fresh()->hasPermissionTo('users.manage'))->toBeFalse();
});

it('permits a role permission change that does not touch users.manage at all', function () {
    $member = Role::findByName('member');

    expect($member->hasPermissionTo('periods.manage'))->toBeFalse();

    $withPeriodsManage = [...RolesAndPermissionsSeeder::MEMBER_PERMISSIONS, 'periods.manage'];

    app(SetRolePermissions::class)->handle($member, $withPeriodsManage);

    expect($member->fresh()->hasPermissionTo('periods.manage'))->toBeTrue();
});

it('permits re-granting users.manage to a role that already holds it', function () {
    $admin = Role::findByName('admin');
    $solo = User::factory()->create();
    $solo->assignRole('admin');

    // Reassigning the SAME permission set that already grants users.manage
    // is not a reduction at all -- the guard's first check is true, so the
    // holder-count branch never runs.
    app(SetRolePermissions::class)->handle($admin, RolesAndPermissionsSeeder::PERMISSIONS);

    expect($admin->fresh()->hasPermissionTo('users.manage'))->toBeTrue();
});

it('changes what can() answers in the same request once the permission cache is forgotten', function () {
    $member = Role::findByName('member');
    $user = User::factory()->create();
    $user->assignRole('member');

    // Warms Spatie\Permission\PermissionRegistrar's cache BEFORE the
    // change, the same way a real request would have already resolved a
    // Gate check earlier in its lifecycle (a nav guard, a policy call
    // elsewhere on the page) before reaching the code that acts on a
    // toggle just made. This assertion is not the interesting one, but
    // skipping it would let the test pass by accident if the cache were
    // never populated at all.
    expect($user->can('directories.create'))->toBeTrue();

    $withoutDirectoriesCreate = array_values(array_diff(RolesAndPermissionsSeeder::MEMBER_PERMISSIONS, ['directories.create']));

    app(SetRolePermissions::class)->handle($member, $withoutDirectoriesCreate);

    // Deliberately the SAME $user instance, with no ->fresh() and no new
    // HTTP request or Livewire::actingAs() call: re-resolving the user (or
    // the app) in a fresh request would prove nothing about this clause,
    // because a fresh request boots nothing that could carry a stale cache
    // forward in the first place -- it would pass whether or not
    // SetRolePermissions ever called forgetCachedPermissions(). This
    // assertion fails, in the SAME PHP process and the SAME $user object,
    // unless the action clears PermissionRegistrar's cache -- which is
    // exactly clause one of item/admin-roles's doneWhen.
    expect($user->can('directories.create'))->toBeFalse();
});

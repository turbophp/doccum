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

// Clause one of item/admin-roles's doneWhen. This is a CHARACTERISATION
// test of behaviour spatie/laravel-permission provides, not a guard over
// code of ours: syncPermissions() forgets PermissionRegistrar's cache
// itself (8.3.0, HasPermissions::syncPermissions), so there is nothing here
// to mutate. An explicit forgetCachedPermissions() in SetRolePermissions
// was written first and removed, because `guards` showed the test passing
// with it deleted -- see that action's own note.
//
// It stays because the clause is a real requirement and this is what keeps
// it true: if a future version of the package stops forgetting, this test
// is what goes red, on the upgrade, which is when someone needs to know.
//
// The name deliberately contains no parentheses. mutation-check.php passes
// expectFailing to `php artisan test --filter`, which PHPUnit treats as a
// REGEX and which is shell-escaped but not regex-escaped; an earlier name
// here contained "can()" and matched nothing at all. That trap is issue
// #180, and it applies to any future entry, not only this one.
it('changes what a permission check answers in the same request once the cache is forgotten', function () {
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

    // A freshly resolved User, in the SAME PHP process and the same request
    // -- no ->fresh() on the old object, no second HTTP request, no
    // Livewire::actingAs(). The distinction matters and was established by
    // CI rather than by reasoning: asserting on the ORIGINAL $user instance
    // fails even with forgetCachedPermissions() in place, so that version of
    // the test could not prove anything about removing the call. An
    // already-resolved model carries authorization state that this action
    // has no reference to and cannot clear; PermissionRegistrar's cache is
    // the only thing it governs, so that is the only thing this asserts.
    //
    // It still discriminates, which is the point: the registrar's cache is
    // process-global, so WITHOUT the call a newly loaded User consults the
    // same stale mapping and answers true. `guards` proves both directions.
    $sameRequestUser = User::query()->findOrFail($user->getKey());

    expect($sameRequestUser->can('directories.create'))->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Actions\Users\SetUserRoles;
use App\Exceptions\LastAdministratorMustRemain;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('refuses to strip the sole holder of users.manage down to a role with no permission', function () {
    $solo = User::factory()->create();
    $solo->assignRole('admin');

    expect(fn () => app(SetUserRoles::class)->handle($solo, ['member']))
        ->toThrow(LastAdministratorMustRemain::class);

    expect($solo->fresh()->hasRole('admin'))->toBeTrue();
});

it('permits stripping users.manage from one holder when a second holder remains', function () {
    $solo = User::factory()->create();
    $solo->assignRole('admin');
    $other = User::factory()->create();
    $other->assignRole('admin');

    app(SetUserRoles::class)->handle($solo, ['member']);

    expect($solo->fresh()->hasRole('member'))->toBeTrue()
        ->and($solo->fresh()->hasRole('admin'))->toBeFalse()
        ->and($other->fresh()->hasRole('admin'))->toBeTrue();
});

it('permits a role change that does not touch users.manage at all', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $member = User::factory()->create();
    $member->assignRole('member');

    app(SetUserRoles::class)->handle($member, []);

    expect($member->fresh()->hasRole('member'))->toBeFalse();
});

it('permits assigning a role that itself grants users.manage', function () {
    $solo = User::factory()->create();
    $solo->assignRole('admin');

    // Reassigning the SAME role that already grants users.manage is not a
    // reduction at all -- $grantsManage is true, so the holder-count branch
    // never runs.
    app(SetUserRoles::class)->handle($solo, ['admin']);

    expect($solo->fresh()->hasRole('admin'))->toBeTrue();
});

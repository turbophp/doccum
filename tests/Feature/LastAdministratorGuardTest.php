<?php

declare(strict_types=1);

use App\Exceptions\LastAdministratorMustRemain;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

it('refuses to delete the sole holder of users.manage', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $solo = User::factory()->create();
    $solo->assignRole('admin');

    expect(fn () => $solo->delete())->toThrow(LastAdministratorMustRemain::class);

    expect(User::whereKey($solo->id)->exists())->toBeTrue();
});

it('permits deleting one holder of users.manage when a second holder remains', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $solo = User::factory()->create();
    $solo->assignRole('admin');
    $other = User::factory()->create();
    $other->assignRole('admin');

    $solo->delete();

    expect(User::whereKey($solo->id)->exists())->toBeFalse()
        ->and(User::whereKey($other->id)->exists())->toBeTrue();
});

it('permits deleting a user who never held users.manage in the first place', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $member = User::factory()->create();
    $member->assignRole('member');

    $member->delete();

    expect(User::whereKey($member->id)->exists())->toBeFalse();
});

// Load-bearing per the guard's own docblock: without this early return, an
// instance where the permission itself does not exist (never seeded, or
// renamed) would refuse to delete ANY user at all, because "no other
// holder exists" is trivially true of every deletion when nobody can hold
// it in the first place.
it('permits deleting a user when users.manage has never been seeded at all', function () {
    $lone = User::factory()->create();

    $lone->delete();

    expect(User::whereKey($lone->id)->exists())->toBeFalse();
});

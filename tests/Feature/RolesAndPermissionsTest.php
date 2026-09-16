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

<?php

declare(strict_types=1);

use App\Livewire\Admin\Roles;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->member = User::factory()->create();
    $this->member->assignRole('member');
});

it('is 403 for a viewer without users.manage', function () {
    $this->actingAs($this->member)
        ->get(route('admin.roles'))
        ->assertForbidden();
});

it('is 200 for a viewer with users.manage', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.roles'))
        ->assertOk();
});

it('refuses the Livewire component itself to a viewer without users.manage', function () {
    Livewire::actingAs($this->member)
        ->test(Roles::class)
        ->assertForbidden();
});

it('saves a toggled permission for a role through the admin roles page', function () {
    $memberRole = SpatieRole::findByName('member');

    expect($memberRole->hasPermissionTo('periods.manage'))->toBeFalse();

    Livewire::actingAs($this->admin)
        ->test(Roles::class)
        // The whole list for the row, not a dotted path to one box: Livewire
        // reads a dot in a property path as nesting, and every permission
        // name contains one.
        ->set("permissionChoice.{$memberRole->id}", ['files.upload', 'periods.manage'])
        ->call('saveRole', $memberRole->id)
        ->assertHasNoErrors();

    expect($memberRole->fresh()->hasPermissionTo('periods.manage'))->toBeTrue();
});

it('surfaces the last-administrator refusal through the admin roles page instead of a 500', function () {
    $adminRole = SpatieRole::findByName('admin');

    // Every permission stays checked EXCEPT users.manage -- $this->admin is
    // the sole holder, so unchecking only that one box on the admin role
    // must be refused.
    $choices = collect(RolesAndPermissionsSeeder::PERMISSIONS)
        ->reject(fn (string $name) => $name === 'users.manage')
        ->values()
        ->all();

    Livewire::actingAs($this->admin)
        ->test(Roles::class)
        ->set("permissionChoice.{$adminRole->id}", $choices)
        ->call('saveRole', $adminRole->id)
        ->assertHasErrors('lastAdministrator');

    expect($adminRole->fresh()->hasPermissionTo('users.manage'))->toBeTrue();
});

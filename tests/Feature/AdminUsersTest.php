<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Livewire\Admin\Users;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->member = User::factory()->create();
    $this->member->assignRole('member');
});

it('is 403 for a viewer without users.manage', function () {
    $this->actingAs($this->member)
        ->get(route('admin.users'))
        ->assertForbidden();
});

it('is 200 for a viewer with users.manage', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.users'))
        ->assertOk();
});

it('refuses the Livewire component itself to a viewer without users.manage', function () {
    Livewire::actingAs($this->member)
        ->test(Users::class)
        ->assertForbidden();
});

// doneWhen: "A user created through the UI has a home directory and a
// manage grant." CreateHomeDirectory is the ONLY thing that writes either
// row (see its own docblock), so this is really asserting that the admin
// page's save() reaches it at all.
it('gives a user created through the admin page a home directory and a manage grant on it', function () {
    Livewire::actingAs($this->admin)
        ->test(Users::class)
        ->set('name', 'Ada Lovelace')
        ->set('username', 'ada')
        ->set('email', 'ada@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->set('role', 'member')
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'ada@example.com')->firstOrFail();

    $home = Directory::where('home_user_id', $user->id)->first();

    expect($home)->not->toBeNull()
        ->and($home->name)->toBe('ada')
        ->and($user->hasRole('member'))->toBeTrue();

    $grant = DirectoryGrant::where('directory_id', $home->id)
        ->where('grantee_type', 'user')
        ->where('grantee_id', $user->id)
        ->first();

    expect($grant)->not->toBeNull()
        ->and($grant->level)->toBe(AccessLevel::Manage);
});

it('rejects a duplicate username through the admin page as a validation error, not an exception', function () {
    User::factory()->create(['username' => 'taken']);

    Livewire::actingAs($this->admin)
        ->test(Users::class)
        ->set('name', 'Someone Else')
        ->set('username', 'taken')
        ->set('email', 'someone-else@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('save')
        ->assertHasErrors('username');
});

it('requires a username on admin user creation, per spec', function () {
    Livewire::actingAs($this->admin)
        ->test(Users::class)
        ->set('name', 'No Username')
        ->set('username', '')
        ->set('email', 'no-username@example.com')
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('save')
        ->assertHasErrors('username');
});

it('lets an admin change their own role away from admin when a second users.manage holder remains', function () {
    $secondAdmin = User::factory()->create();
    $secondAdmin->assignRole('admin');

    Livewire::actingAs($this->admin)
        ->test(Users::class)
        ->set("roleChoice.{$this->admin->id}", 'member')
        ->call('changeRole', $this->admin->id)
        ->assertHasNoErrors();

    expect($this->admin->fresh()->hasRole('member'))->toBeTrue()
        ->and($this->admin->fresh()->hasRole('admin'))->toBeFalse();
});

it('surfaces the last-administrator refusal through the admin page instead of a 500', function () {
    Livewire::actingAs($this->admin)
        ->test(Users::class)
        ->set("roleChoice.{$this->admin->id}", '')
        ->call('changeRole', $this->admin->id)
        ->assertHasErrors('lastAdministrator');

    expect($this->admin->fresh()->hasRole('admin'))->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Models\Directory;
use App\Models\User;
use App\Services\Settings;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(Database\Seeders\RolesAndPermissionsSeeder::class));

it('redirects to setup while the instance has no users', function () {
    $this->get('/')->assertRedirect(route('setup'));
    $this->get(route('login'))->assertRedirect(route('setup'));
});

it('serves the setup screen when there are no users', function () {
    $this->get(route('setup'))->assertOk();
});

it('creates the first admin with a home directory', function () {
    Livewire::test(App\Livewire\Setup\FirstRun::class)
        ->set('instance_name', 'Acme Docs')
        ->set('name', 'Ada Lovelace')
        ->set('username', 'ada')
        ->set('email', 'ada@example.com')
        ->set('password', 'password-please')
        ->set('password_confirmation', 'password-please')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    $user = User::firstOrFail();

    expect($user->username)->toBe('ada')
        ->and($user->hasRole('admin'))->toBeTrue()
        ->and(app(Settings::class)->get('instance.name'))->toBe('Acme Docs')
        ->and(Directory::where('home_user_id', $user->id)->value('name'))->toBe('ada')
        ->and(auth()->check())->toBeTrue();
});

it('validates the first admin', function () {
    Livewire::test(App\Livewire\Setup\FirstRun::class)
        ->set('username', 'Not A Username!')
        ->set('email', 'nope')
        ->call('submit')
        ->assertHasErrors(['username', 'email', 'name', 'password']);

    expect(User::count())->toBe(0);
});

it('closes setup once a user exists', function () {
    User::factory()->create();

    $this->get(route('setup'))->assertNotFound();
});

it('stops redirecting once a user exists', function () {
    User::factory()->create();

    $this->get(route('login'))->assertOk();
});

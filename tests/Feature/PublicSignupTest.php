<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // An instance with no users at all redirects every route to the
    // first-run setup screen; the public signup toggle only matters once an
    // instance already has its first (admin) user.
    User::factory()->create();
});

it('hides the register page by default', function () {
    $this->get(route('register'))->assertNotFound();
});

it('refuses a registration post by default', function () {
    $this->post(route('register.store'), [
        'name' => 'Ada', 'username' => 'ada', 'email' => 'ada@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertNotFound();

    expect(User::where('email', 'ada@example.com')->exists())->toBeFalse();
});

it('serves the register page when the operator enables signup', function () {
    app(Settings::class)->set('auth.public_signup', true);

    $this->get(route('register'))->assertOk();
});

it('accepts a registration when signup is enabled', function () {
    app(Settings::class)->set('auth.public_signup', true);

    $this->post(route('register.store'), [
        'name' => 'Ada', 'username' => 'ada', 'email' => 'ada@example.com',
        'password' => 'password', 'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'ada@example.com')->exists())->toBeTrue();
});

it('leaves login reachable regardless', function () {
    $this->get(route('login'))->assertOk();
});

<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Settings;
use Illuminate\Database\QueryException;

beforeEach(fn () => app(Settings::class)->set('auth.public_signup', true));

it('stores a username on a user', function () {
    $user = User::factory()->create(['username' => 'ada']);

    expect($user->fresh()->username)->toBe('ada');
});

it('refuses a duplicate username', function () {
    User::factory()->create(['username' => 'ada']);

    expect(fn () => User::factory()->create(['username' => 'ada']))
        ->toThrow(QueryException::class);
});

it('generates a unique username from the factory', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    expect($a->username)->not->toBe($b->username)
        ->and($a->username)->toMatch('/^[a-z0-9._-]+$/');
});

it('requires a username to register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('username');
    expect(User::where('email', 'ada@example.com')->exists())->toBeFalse();
});

it('rejects a username that is already taken', function () {
    User::factory()->create(['username' => 'ada']);

    $response = $this->post(route('register.store'), [
        'name' => 'Someone Else',
        'username' => 'ada',
        'email' => 'else@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('username');
});

it('rejects a username with characters that are unsafe in a folder name', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'username' => 'Ada Lovelace!',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors('username');
});

it('registers a user with a valid username', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Ada Lovelace',
        'username' => 'ada.lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors();
    expect(User::where('email', 'ada@example.com')->value('username'))->toBe('ada.lovelace');
});

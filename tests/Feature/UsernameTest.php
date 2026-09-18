<?php

declare(strict_types=1);

use App\Exceptions\UsernameWouldBeAmbiguous;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Database\QueryException;

beforeEach(function () {
    app(Settings::class)->set('auth.public_signup', true);

    // An instance with no users at all redirects every route to the
    // first-run setup screen; the tests below that hit the register routes
    // need an instance that already has its first (admin) user.
    User::factory()->create();
});

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

/**
 * item/login-by-username (issue #75). AuthenticateUser resolves a login
 * identifier by asking whether it contains `@`: with one it looks up an
 * email, without one a username. That dispatch is unambiguous only while the
 * two character sets stay disjoint.
 *
 * usernameRules() excludes `@` at both application write paths, and this
 * asserts the guard BELOW those -- User::booted()'s saving hook -- which is
 * what makes the rule true for a path that never validates at all. The
 * factory is already one such path.
 *
 * The consequence of losing this is not a failed login: a username equal to
 * another account's email sends the typing user to THAT account's row.
 */
it('refuses to persist a username containing an at sign, whatever wrote it', function () {
    expect(fn () => User::factory()->create(['username' => 'ada@example.com']))
        ->toThrow(UsernameWouldBeAmbiguous::class);

    expect(User::query()->where('email', 'ada@example.com')->exists())->toBeFalse();
});

it('folds a username to lower case on save, from any path', function () {
    $user = User::factory()->create(['username' => '  AdaLovelace  ']);

    expect($user->fresh()->username)->toBe('adalovelace');
});

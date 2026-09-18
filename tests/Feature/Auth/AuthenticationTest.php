<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        // An instance with no users at all redirects every route to the
        // first-run setup screen; create one so this exercises a "normal"
        // guest visit to the login screen instead.
        User::factory()->create();

        $response = $this->get(route('login'));

        $response->assertOk();
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            // Files is the default landing after login (spec §10,
            // "Destinations"), not the dashboard.
            ->assertRedirect(route('files.browse', absolute: false));

        $this->assertAuthenticated();
    }

    // General coverage for the rule App\Actions\Fortify\AuthenticateUser
    // states explicitly (App\Support\EmailKey), independent of whichever
    // write path stored this particular row: a login attempt in different
    // case than the stored email still resolves to the same account.
    public function test_users_can_authenticate_with_a_differently_cased_email(): void
    {
        $user = User::factory()->create(['email' => 'dana@example.com']);

        $response = $this->post(route('login.store'), [
            'email' => 'Dana@Example.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrorsIn('email');

        $this->assertGuest();
    }

    // item/login-by-username (issue #75), spec §10: "Login accepts either
    // username or email." The login form still posts the identifier under
    // the 'email' key (see resources/views/livewire/auth/login.blade.php
    // and App\Providers\FortifyServiceProvider's rate limiter, both of
    // which key off it) -- only AuthenticateUser's dispatch on that value
    // changed.
    public function test_users_can_authenticate_using_their_username(): void
    {
        $user = User::factory()->create(['username' => 'dana']);

        $response = $this->post(route('login.store'), [
            'email' => 'dana',
            'password' => 'password',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    // Usernames are already lowercase-only by usernameRules()'s regex, but
    // AuthenticateUser folds the submitted identifier the same way as email
    // rather than leaning on that as an implicit guarantee (decision/0010) --
    // this proves the fold, not merely that a lowercase username matches
    // itself.
    public function test_users_can_authenticate_with_a_differently_cased_username(): void
    {
        $user = User::factory()->create(['username' => 'dana']);

        $response = $this->post(route('login.store'), [
            'email' => 'DANA',
            'password' => 'password',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_not_authenticate_by_username_with_invalid_password(): void
    {
        $user = User::factory()->create(['username' => 'dana']);

        $response = $this->post(route('login.store'), [
            'email' => $user->username,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrorsIn('email');

        $this->assertGuest();
    }

    // The disjointness AuthenticateUser's docblock states: an identifier
    // containing "@" is dispatched to the `email` lookup, never `username`,
    // so a value shaped like an email never matches by username even when
    // no account's email happens to equal it either -- there is no fallback
    // between the two lookups to observe here, which is the point.
    public function test_an_email_shaped_identifier_never_resolves_by_username(): void
    {
        // The account's USERNAME is what this string would be if the
        // dispatch fell back to a username lookup on a failed email match.
        User::factory()->create(['username' => 'nobody-at-example.com', 'email' => 'someone-else@example.com']);

        $response = $this->post(route('login.store'), [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrorsIn('email');

        $this->assertGuest();
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();
    }
}

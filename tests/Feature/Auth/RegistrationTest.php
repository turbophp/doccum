<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());

        app(Settings::class)->set('auth.public_signup', true);

        // An instance with no users at all redirects every route to the
        // first-run setup screen; the self-service register page only makes
        // sense once an instance already has its first (admin) user.
        User::factory()->create();
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'John Doe',
            'username' => 'johndoe',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasNoErrors()
            // Files is the default landing after login (spec §10,
            // "Destinations"), not the dashboard.
            ->assertRedirect(route('files.browse', absolute: false));

        $this->assertAuthenticated();
    }

    // Issue #59, and the actual reproduction of it: the existing row is
    // seeded with a mixed-case email directly (bypassing the HTTP register
    // route entirely), because config('fortify.lowercase_usernames') means
    // Fortify's OWN RegisteredUserController already folds whatever is
    // POSTed to /register before CreateNewUser ever sees it -- registering
    // twice through the form alone would never have reproduced this bug.
    // The exploitable gap is the account this project's own unfolded write
    // paths (the first-run installer, a profile update) can leave behind;
    // this seeds that row the same way and confirms a plain registration
    // afterwards is decided by its folded value regardless.
    //
    // Before the fix: `where email = 'alice@example.com'` does not match a
    // stored 'Alice@Example.com' row on SQLite or PostgreSQL -- a byte
    // comparison -- so Rule::unique() wrongly reports the address free and
    // this registration is wrongly ACCEPTED there, creating a second
    // account. MySQL's utf8mb4_unicode_ci column collation folds case for
    // this comparison already, so it alone was never wrong. See
    // App\Support\EmailKey.
    public function test_registering_an_email_that_only_differs_by_case_from_an_unfolded_row_is_rejected(): void
    {
        User::factory()->create(['email' => 'Alice@Example.com']);

        $response = $this->post(route('register.store'), [
            'name' => 'Second Alice',
            'username' => 'alice2',
            'email' => 'alice@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');

        $this->assertSame(
            1,
            User::whereIn('email', ['Alice@Example.com', 'alice@example.com'])->count(),
            'A second account was created for a case-variant of an existing email.',
        );
    }

    // A straightforward regression check, not a "shown failing" one: Fortify's
    // own RegisteredUserController already lowercases this specific field
    // before CreateNewUser ever runs (config('fortify.lowercase_usernames')),
    // so this already passed before this item too. Kept because it is still
    // the right assertion for what the stored value is supposed to be.
    public function test_a_registered_email_is_stored_folded_to_lowercase(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Alice',
            'username' => 'alice',
            'email' => 'Alice@Example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(User::where('email', 'alice@example.com')->exists());
        $this->assertFalse(User::where('email', 'Alice@Example.com')->exists());
    }
}

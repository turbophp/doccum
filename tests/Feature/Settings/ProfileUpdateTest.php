<?php

namespace Tests\Feature\Settings;

use App\Livewire\Settings\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get('/settings/profile')->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test(Profile::class)
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test(Profile::class)
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_profile_email_is_folded_to_lowercase_when_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('name', $user->name)
            ->set('email', 'Carol@Example.com')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('carol@example.com', $user->refresh()->email);
    }

    // Issue #59: Profile is a fully custom Livewire component -- it never
    // goes through Fortify's own ProfileInformationController, so nothing
    // upstream folded the submitted value before this item. Before the fix,
    // `where email = 'bob@example.com'` (this update's submitted value, used
    // verbatim by Rule::unique()) does not match a stored 'Bob@Example.com'
    // row on SQLite or PostgreSQL -- a byte comparison -- so the update was
    // wrongly ACCEPTED there, leaving two accounts that differ only by case.
    // MySQL's utf8mb4_unicode_ci column collation already folded this
    // comparison, so it alone was never wrong.
    public function test_updating_email_to_a_case_variant_of_an_existing_account_is_rejected(): void
    {
        User::factory()->create(['email' => 'Bob@Example.com']);
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test(Profile::class)
            ->set('name', $user->name)
            ->set('email', 'bob@example.com')
            ->call('updateProfileInformation');

        $response->assertHasErrors(['email']);

        $this->assertNotEquals('bob@example.com', $user->refresh()->email);
    }

    // Issue #59's finding #1: config('fortify.lowercase_usernames') already
    // folds a login attempt's submitted email (Laravel\Fortify\Actions\
    // CanonicalizeUsername), but nothing folded what Profile itself stored
    // before this item -- so a user who saved their email as "Carol@Example.
    // com" here and later typed it back in lowercase (or any other case)
    // hit a guard->attempt() comparing a folded credential against an
    // UNfolded stored row: a byte comparison that never matches on SQLite
    // or PostgreSQL. They could not log in again at all, on those two
    // drivers, until they remembered the exact case they originally typed.
    public function test_user_can_log_in_after_changing_their_email_to_mixed_case(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->set('name', $user->name)
            ->set('email', 'Carol@Example.com')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->post(route('logout'));
        $this->assertGuest();

        $response = $this->post(route('login.store'), [
            'email' => 'carol@example.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('settings.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');

        $response
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertFalse(auth()->check());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('settings.delete-user-form')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $response->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }
}

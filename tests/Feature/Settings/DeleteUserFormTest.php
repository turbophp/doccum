<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Livewire\Settings\DeleteUserForm;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * item/admin-users (issue #18): App\Livewire\Settings\ProfileUpdateTest
 * already covers the ordinary case (a user with no special role deletes
 * themselves and it succeeds) -- deliberately not duplicated here. This
 * covers the NEW case: the sole holder of `users.manage` deleting
 * themselves through the SAME form must be refused, readably, and must
 * stay both logged in and un-deleted.
 */
class DeleteUserFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sole_administrator_is_refused_when_deleting_their_own_account(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $solo = User::factory()->create();
        $solo->assignRole('admin');

        $this->actingAs($solo);

        $response = Livewire::test(DeleteUserForm::class)
            ->set('password', 'password')
            ->call('deleteUser');

        $response->assertHasErrors(['password']);

        $this->assertNotNull($solo->fresh());

        // The whole point of checking BEFORE $logout(): the refusal must
        // not have ended the viewer's own session either. See
        // DeleteUserForm::deleteUser()'s own docblock.
        $this->assertTrue(auth()->check());
    }

    public function test_an_administrator_can_delete_their_own_account_when_a_second_administrator_remains(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $solo = User::factory()->create();
        $solo->assignRole('admin');
        $other = User::factory()->create();
        $other->assignRole('admin');

        $this->actingAs($solo);

        $response = Livewire::test(DeleteUserForm::class)
            ->set('password', 'password')
            ->call('deleteUser');

        $response->assertHasNoErrors()->assertRedirect('/');

        $this->assertNull($solo->fresh());
        $this->assertFalse(auth()->check());
    }
}

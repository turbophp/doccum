<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Enums\ApiTokenAbility;
use App\Livewire\Settings\ApiTokens;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * item/api-sanctum-tokens (issue #22). Covers the doneWhen's own three
 * clauses: a token page test (this whole class), an ability outside the
 * eight being rejected (also covered, at the layer that matters, by
 * tests/Feature/PersonalAccessTokenAbilityGuardTest.php -- see that file's
 * own docblock for why the guard itself lives on the model rather than
 * here), and the plaintext token never appearing in a later render
 * (test_the_plaintext_token_never_appears_in_a_later_render below).
 */
class ApiTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_api_tokens_page_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('api-tokens.edit'));

        $response->assertOk();
        $response->assertSee('API tokens');
        $response->assertSee('No tokens yet');
    }

    public function test_the_api_tokens_page_requires_password_confirmation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('api-tokens.edit'));

        $response->assertRedirect(route('password.confirm'));
    }

    public function test_a_token_can_be_created_with_a_chosen_name_and_abilities(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(ApiTokens::class)
            ->set('name', 'CI token')
            ->set('selectedAbilities', [ApiTokenAbility::Search->value, ApiTokenAbility::FilesRead->value])
            ->call('createToken')
            ->assertHasNoErrors();

        $token = $user->tokens()->sole();

        $this->assertSame('CI token', $token->name);
        $this->assertSame(
            [ApiTokenAbility::Search->value, ApiTokenAbility::FilesRead->value],
            $token->abilities,
        );
    }

    public function test_creating_a_token_through_the_settings_form_refuses_an_ability_outside_the_eight(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(ApiTokens::class)
            ->set('name', 'Bad token')
            ->set('selectedAbilities', ['directories:read', 'purge'])
            ->call('createToken')
            ->assertHasErrors();

        // Refused BEFORE ever reaching App\Actions\ApiTokens\CreateApiToken
        // -- Livewire's own validate() short-circuits the method, so nothing
        // was persisted at all, not even a row later rolled back.
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_plaintext_token_never_appears_in_a_later_render(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $component = Livewire::test(ApiTokens::class)
            ->set('name', 'CI token')
            ->set('selectedAbilities', [ApiTokenAbility::Search->value])
            ->call('createToken')
            ->assertHasNoErrors();

        $plainTextToken = $component->get('plainTextToken');

        $this->assertIsString($plainTextToken);
        $this->assertNotSame('', $plainTextToken);

        // The response that JUST created it is the one place it may appear.
        $component->assertSee($plainTextToken);

        // A LATER render -- a fresh mount, the shape a page reload or a new
        // tab actually takes -- must NOT contain it. This is the clause's
        // real proof: not that a "copy this now" notice was shown once, but
        // that the value itself is unrecoverable afterwards.
        Livewire::test(ApiTokens::class)->assertDontSee($plainTextToken);

        // The same claim again through a real HTTP request rather than a
        // fresh component instance, so this does not rest on Livewire's
        // testing harness alone doing something a browser reload would not.
        $this->get(route('api-tokens.edit'))->assertDontSee($plainTextToken);
    }

    public function test_a_token_can_be_revoked(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $token = $user->createToken('to revoke', [ApiTokenAbility::Search->value]);
        $tokenId = (int) $token->accessToken->getKey();

        Livewire::test(ApiTokens::class)
            ->call('confirmRevoke', $tokenId)
            ->assertSet('showRevokeModal', true)
            ->assertSet('revokingTokenName', 'to revoke')
            ->call('revokeToken')
            ->assertSet('showRevokeModal', false);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_revoking_a_token_clears_a_plaintext_token_shown_for_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Livewire::test(ApiTokens::class)
            ->set('name', 'CI token')
            ->set('selectedAbilities', [ApiTokenAbility::Search->value])
            ->call('createToken')
            ->assertHasNoErrors();

        $plainTextToken = $component->get('plainTextToken');
        $tokenId = $component->get('plainTextTokenId');

        $component->call('confirmRevoke', $tokenId)
            ->call('revokeToken')
            ->assertSet('plainTextToken', null);

        $component->assertDontSee($plainTextToken);
    }

    public function test_a_user_cannot_revoke_another_users_token(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $token = $owner->createToken('owner token', [ApiTokenAbility::Search->value]);
        $tokenId = (int) $token->accessToken->getKey();

        $this->actingAs($stranger);

        Livewire::test(ApiTokens::class)
            ->call('confirmRevoke', $tokenId)
            ->assertNotFound();

        $this->assertSame(1, $owner->tokens()->count());
    }
}

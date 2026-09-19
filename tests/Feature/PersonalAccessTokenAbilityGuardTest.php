<?php

declare(strict_types=1);

use App\Enums\ApiTokenAbility;
use App\Exceptions\InvalidApiTokenAbility;
use App\Models\User;

/**
 * item/api-sanctum-tokens (issue #22): "abilities outside the eight are
 * rejected" has to hold for a token minted by ANY path, not merely the one
 * App\Livewire\Settings\ApiTokens happens to expose today. Every test here
 * therefore goes around that Livewire form entirely and calls
 * Laravel\Sanctum\HasApiTokens::createToken() directly -- exactly what a
 * future /api/v1 endpoint, a console command, or Tinker would call -- to
 * prove the guard lives on App\Models\PersonalAccessToken itself and not
 * merely on the form's own validation rule.
 */
it('rejects an ability outside the eight when a token is created directly, bypassing the settings form', function () {
    $user = User::factory()->create();

    expect(fn () => $user->createToken('bypass', ['directories:read', 'purge']))
        ->toThrow(InvalidApiTokenAbility::class);

    expect($user->tokens()->count())->toBe(0);
});

it('rejects the wildcard ability, which is also outside the eight', function () {
    $user = User::factory()->create();

    // Laravel\Sanctum\HasApiTokens::createToken() defaults its $abilities
    // argument to ['*'] when none is given at all -- the guard has to
    // refuse that default too, not just a made-up string, or a future
    // caller that forgets to pass abilities would mint a full-access token
    // spec §11 never sanctions.
    expect(fn () => $user->createToken('no-abilities-given'))
        ->toThrow(InvalidApiTokenAbility::class);
});

it('accepts every one of the eight abilities together, whatever created the token', function () {
    $user = User::factory()->create();

    $token = $user->createToken('full-scope', ApiTokenAbility::values());

    expect($token->accessToken->abilities)->toBe(ApiTokenAbility::values());
});

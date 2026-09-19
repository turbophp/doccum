<?php

declare(strict_types=1);

namespace App\Actions\ApiTokens;

use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues a personal access token for a user.
 *
 * item/api-sanctum-tokens (issue #22). A thin wrapper around
 * Laravel\Sanctum\HasApiTokens::createToken() rather than a Livewire method
 * calling it directly, so the same entry point is reusable from
 * item/api-content's own surface later without duplicating this line.
 *
 * This action does NOT authorise -- CLAUDE.md: actions never authorise,
 * callers do. There is no cross-user question to skip here either way:
 * App\Livewire\Settings\ApiTokens is the only caller today, and it always
 * passes Auth::user() -- a signed-in user minting a token for themselves,
 * the same "no policy needed, ownership is the check" shape
 * App\Livewire\Settings\Security already uses for a user's own passkeys.
 *
 * Abilities are NOT re-validated here. App\Models\PersonalAccessToken::
 * booted() is where that guard lives -- on the model, not the action --
 * precisely so it also holds for a token created by any other path this
 * action does not mediate. See that class's own docblock.
 */
class CreateApiToken
{
    /**
     * @param  list<string>  $abilities
     */
    public function handle(User $user, string $name, array $abilities, ?Carbon $expiresAt = null): NewAccessToken
    {
        return $user->createToken($name, $abilities, $expiresAt);
    }
}

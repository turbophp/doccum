<?php

declare(strict_types=1);

namespace App\Actions\ApiTokens;

use App\Models\PersonalAccessToken;

/**
 * Revokes (deletes) a personal access token.
 *
 * item/api-sanctum-tokens (issue #22). This action does NOT authorise --
 * CLAUDE.md: actions never authorise, callers do. App\Livewire\Settings\
 * ApiTokens is the only caller, and it resolves the token through
 * Auth::user()->tokens() before handing it here, so by the time handle()
 * runs the caller has already proven the token belongs to the signed-in
 * user -- the same ownership-scoped-query shape App\Livewire\Settings\
 * Security uses for deletePasskey().
 */
class RevokeApiToken
{
    public function handle(PersonalAccessToken $token): void
    {
        $token->delete();
    }
}

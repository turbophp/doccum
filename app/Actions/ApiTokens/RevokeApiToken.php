<?php

declare(strict_types=1);

namespace App\Actions\ApiTokens;

use Laravel\Sanctum\PersonalAccessToken;

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
 *
 * Typed with SANCTUM's PersonalAccessToken rather than doccum's subclass,
 * matching what HasApiTokens::tokens() is statically declared to return.
 * Every token reaching here really is an App\Models\PersonalAccessToken --
 * Sanctum::usePersonalAccessTokenModel() swaps the model in
 * DoccumServiceProvider -- but that is a runtime fact, and narrowing the
 * parameter to the subclass would make the caller's relation type a lie
 * rather than make this any safer. delete() is on the base class.
 */
class RevokeApiToken
{
    public function handle(PersonalAccessToken $token): void
    {
        $token->delete();
    }
}

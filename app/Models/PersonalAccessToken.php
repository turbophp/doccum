<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiTokenAbility;
use App\Exceptions\InvalidApiTokenAbility;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * doccum's own personal access token, swapped in for Sanctum's stock model
 * via Sanctum::usePersonalAccessTokenModel() in
 * App\Providers\DoccumServiceProvider::boot() -- the package's own
 * documented extension point for exactly this, the same shape as
 * Storage::extend() for the Azure driver beside it. Not a vendor edit: the
 * upgrade seam (CLAUDE.md) stays intact because nothing under vendor/ is
 * touched, and the base class is still free to gain columns or behaviour on
 * a future Sanctum upgrade.
 *
 * item/api-sanctum-tokens (issue #22). This class exists for exactly one
 * reason: booted() below refuses to save a token carrying an ability outside
 * App\Enums\ApiTokenAbility's fixed eight. See that exception's own docblock
 * for why the guard belongs here rather than only in the Livewire form.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected static function booted(): void
    {
        // Fires on every save -- create AND update -- so a future "edit a
        // token's abilities" path is covered by the same guard with no
        // second call to remember. $token->abilities is already the CAST
        // value (Sanctum casts it 'json') by the time a `saving` listener
        // sees it, whether it arrived through a mass-assigned create() or a
        // direct property set.
        static::saving(static function (self $token): void {
            /** @var list<string> $abilities */
            $abilities = (array) $token->abilities;

            $unknown = array_values(array_diff($abilities, ApiTokenAbility::values()));

            if ($unknown !== []) {
                throw InvalidApiTokenAbility::forAbilities($unknown);
            }
        });
    }
}

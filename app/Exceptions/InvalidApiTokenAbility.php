<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ApiTokenAbility;
use RuntimeException;

/**
 * Thrown when a personal access token is about to be persisted with an
 * ability outside spec §11's fixed set of eight (App\Enums\ApiTokenAbility).
 *
 * Raised from App\Models\PersonalAccessToken::booted(), the same "one hook
 * nothing can bypass" shape as App\Exceptions\UsernameWouldBeAmbiguous and
 * App\Models\User's own email fold: App\Livewire\Settings\ApiTokens already
 * limits its form to the eight known checkboxes, but that is a courtesy to
 * the person filling in a form, not a guard -- a Livewire method call
 * reaches /livewire/update directly with whatever the client sends, and
 * $user->createToken() is public API any future code path (Tinker, a
 * factory, a future /api/v1 endpoint) can call with an arbitrary abilities
 * array. Without a guard that lives on the model itself, a token minted
 * outside this form -- or one whose form validation is later loosened by
 * accident -- could carry a scope spec §11 never sanctioned, including the
 * `*` wildcard Laravel\Sanctum\HasApiTokens::createToken() defaults to when
 * no abilities are given at all.
 */
class InvalidApiTokenAbility extends RuntimeException
{
    /**
     * @param  list<string>  $abilities
     */
    public static function forAbilities(array $abilities): self
    {
        return new self(sprintf(
            'Abilit%s [%s] %s not among the abilities a personal access token may hold: %s.',
            count($abilities) === 1 ? 'y' : 'ies',
            implode(', ', $abilities),
            count($abilities) === 1 ? 'is' : 'are',
            implode(', ', ApiTokenAbility::values()),
        ));
    }
}

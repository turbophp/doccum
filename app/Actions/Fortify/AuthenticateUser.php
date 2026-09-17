<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\EmailKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the user a login attempt is for.
 *
 * config('fortify.lowercase_usernames') already lowercases the submitted
 * credential before this runs (Laravel\Fortify\Actions\CanonicalizeUsername,
 * wired into the login pipeline by AuthenticatedSessionController because
 * that config is true) -- so today, login already tolerates a differently
 * cased email. But that is a semantic borrowed from Fortify's config, not
 * one doccum states in its own code, and decision/0010 is explicit that
 * doccum states every semantic it relies on rather than inheriting one from
 * a framework default. Registered via Fortify::authenticateUsing() so the
 * match is instead made through the exact same App\Support\EmailKey rule
 * that CreateNewUser, FirstRun and Profile fold on write -- one rule, one
 * place, not a config flag this app happens to also set correctly.
 *
 * Only ever compares by email: spec §10 also says login should accept
 * either username or email. That is unimplemented -- this class is the
 * only authenticateUsing customisation in the codebase, and it resolves by
 * `email` alone, deliberately. Accepting username too is a different,
 * separate change (a second lookup column, a decision about what happens
 * when they collide) and is tracked as its own item rather than folded in
 * here.
 *
 * @see \App\Providers\FortifyServiceProvider
 */
class AuthenticateUser
{
    public function __invoke(Request $request): ?User
    {
        $email = $request->input('email');

        if (! is_string($email) || $email === '') {
            return null;
        }

        $provider = Auth::createUserProvider('users');

        if ($provider === null) {
            return null;
        }

        $credentials = ['email' => EmailKey::of($email), 'password' => $request->input('password')];

        $user = $provider->retrieveByCredentials($credentials);

        if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials)) {
            return null;
        }

        return $user;
    }
}

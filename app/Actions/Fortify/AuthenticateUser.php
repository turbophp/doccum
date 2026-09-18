<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Providers\FortifyServiceProvider;
use App\Support\EmailKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the user a login attempt is for, by username or by email
 * (spec §10: "Login accepts either username or email").
 *
 * config('fortify.lowercase_usernames') already lowercases the submitted
 * credential before this runs (Laravel\Fortify\Actions\CanonicalizeUsername,
 * wired into the login pipeline by AuthenticatedSessionController because
 * that config is true) -- so today, login already tolerates a differently
 * cased email or username. But that is a semantic borrowed from Fortify's
 * config, not one doccum states in its own code, and decision/0010 is
 * explicit that doccum states every semantic it relies on rather than
 * inheriting one from a framework default. Registered via
 * Fortify::authenticateUsing() so the match is instead made through the
 * exact same App\Support\EmailKey rule that CreateNewUser, FirstRun and
 * Profile fold on write for email -- one rule, one place, not a config flag
 * this app happens to also set correctly. The username side is folded the
 * same way (trim, then lowercase) for the same stated-not-inherited reason,
 * even though every write path already stores usernames pre-lowercased --
 * see the invariant below.
 *
 * Dispatch: an identifier containing "@" is looked up by `email`; one that
 * does not is looked up by `username`.
 *
 * That dispatch is exact, not a best-effort heuristic, and here is why no
 * precedence rule is needed for what happens when a value is "both a valid
 * username and some other account's email" -- that case is unreachable by
 * construction, not merely unhandled:
 *
 *   - App\Concerns\ProfileValidationRules::usernameRules() constrains every
 *     STORED username to `/^[a-z0-9._-]+$/` -- the character "@" is not in
 *     that set, ever.
 *   - ProfileValidationRules::emailRules() requires Laravel's `email` rule,
 *     which requires an "@".
 *
 * The two character sets are disjoint, so no string can validly be both at
 * once, and the "@" test above is therefore a correct, total partition of
 * every identifier a login form can submit -- not an approximation of one.
 *
 * That disjointness is an INVARIANT of every write path to `users.username`,
 * not a property this class enforces itself -- it holds only for as long as
 * every such path validates through usernameRules() (or an equivalent
 * constraint) before saving. Hand-traced at the time this class was written:
 * App\Actions\Fortify\CreateNewUser::create() (registration) and
 * App\Livewire\Setup\FirstRun::submit() (the one-time admin) both validate
 * with usernameRules() before User::create() -- the only two paths that can
 * mint a `users` row from outside this codebase. (database/factories/
 * UserFactory's default state calls fake()->userName() unvalidated, but it
 * is test/seed-only and never runs against a production database, so it
 * does not put this invariant at risk there. If a real write path to
 * `users.username` is ever added without validating through usernameRules(),
 * this dispatch stops being exact and starts being a heuristic that resolves
 * silently to whichever row `retrieveByCredentials()` happens to find.)
 *
 * @see FortifyServiceProvider
 */
class AuthenticateUser
{
    public function __invoke(Request $request): ?User
    {
        $identifier = $request->input('email');

        if (! is_string($identifier) || $identifier === '') {
            return null;
        }

        $provider = Auth::createUserProvider('users');

        if ($provider === null) {
            return null;
        }

        $column = str_contains($identifier, '@') ? 'email' : 'username';
        $value = $column === 'email' ? EmailKey::of($identifier) : mb_strtolower(trim($identifier));

        $credentials = [$column => $value, 'password' => $request->input('password')];

        $user = $provider->retrieveByCredentials($credentials);

        if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials)) {
            return null;
        }

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The token half of spec §11's three gates: `Route::middleware('ability:...')`
 * on every /api/v1 route, checked BEFORE the route's own Policy call.
 *
 * A missing ability is 403 even when the signed-in user holds the matching
 * Spatie permission and directory_access grant -- an ability only ever
 * NARROWS what a token may do, never widens it (App\Enums\ApiTokenAbility's
 * own docblock). Placing this check ahead of the controller, rather than
 * inside it, is what makes "ability first, then the same two-layer Policy
 * every other caller uses" true for every route rather than a convention
 * each controller could forget.
 *
 * `$request->user()->tokenCan()` returns false with no error for a request
 * authenticated some other way (no current access token at all), which
 * cannot happen on this guard -- every /api/v1 route also carries
 * `auth:sanctum`, and Sanctum's guard for a Bearer token always sets a
 * current access token. There is deliberately no special case for that here:
 * the one true way this could return false is a genuinely missing ability,
 * which is exactly what should answer 403.
 */
class EnsureTokenAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        // $request->user() is typed to the generic Authenticatable
        // contract, which knows nothing about Sanctum's HasApiTokens::
        // tokenCan() -- the same narrowing App\Livewire\Settings\ApiTokens
        // already uses for Auth::user().
        /** @var User|null $user */
        $user = $request->user();

        abort_unless((bool) $user?->tokenCan($ability), 403, "This token lacks the '{$ability}' ability.");

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}

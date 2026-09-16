<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Providers\RuntimeConfigServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the instance has no users at all, every web route leads to the one-time
 * setup screen; once one exists, that screen is gone for good.
 *
 * This is why doccum ships no default credentials: there is never a moment
 * where a known username and password would work.
 */
class RequireInstanceSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        // A present-but-unreadable runtime config means a configured instance,
        // not a fresh one -- serving the installer here would offer to
        // reinstall over live data. Checked before anything else so it applies
        // to every route, setup included.
        if (RuntimeConfigServiceProvider::hasError()) {
            abort(503, RuntimeConfigServiceProvider::error());
        }

        $hasUsers = User::query()->exists();

        // Livewire's own endpoints must pass through, or the setup form can
        // never submit: its POST would be redirected back to /setup before it
        // ever reached the component. Livewire 4 names that route
        // `default-livewire.update`, hence the wildcard rather than a literal.
        //
        // This is safe because Livewire validates the component snapshot
        // against a checksum signed with APP_KEY, so a caller cannot summon an
        // arbitrary component -- only one rendered by a page it was served,
        // and /setup is the only page reachable in this state.
        if (! $hasUsers && ! $request->routeIs('setup', '*livewire.*')) {
            return redirect()->route('setup');
        }

        if ($hasUsers && $request->routeIs('setup')) {
            abort(404);
        }

        return $next($request);
    }
}

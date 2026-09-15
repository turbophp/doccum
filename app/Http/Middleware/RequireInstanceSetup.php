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

        if (! $hasUsers && ! $request->routeIs('setup')) {
            return redirect()->route('setup');
        }

        if ($hasUsers && $request->routeIs('setup')) {
            abort(404);
        }

        return $next($request);
    }
}

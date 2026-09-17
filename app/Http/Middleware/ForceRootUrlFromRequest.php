<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes every URL generated while handling this request -- the login form's
 * action, @vite's asset URLs, a password-reset link mailed synchronously from
 * the same request -- use the scheme and host this request actually arrived
 * on, when the operator never set APP_URL.
 *
 * Only ever does anything when $appUrlIsUnset is true, which
 * DoccumServiceProvider computes from the raw environment at the moment this
 * middleware is resolved (see its binding for ForceRootUrlFromRequest::class)
 * -- once per request, never cached across requests. An operator who *did*
 * set APP_URL gets today's behaviour untouched.
 *
 * Deliberately request-scoped middleware, not a provider's boot(). A single
 * `URL::forceRootUrl(...)` call made once at boot, from whichever request
 * happened to be current at the time, would stick for the lifetime of the
 * process: every other request afterwards, and every queued job that shares
 * that process, would keep generating URLs for that first request's host.
 * Middleware runs fresh per request and never runs at all outside the 'web'
 * group -- console commands, queue workers, the scheduler, and
 * `composer dump-autoload`'s package:discover at image build time -- so
 * there is nothing here that can explode or leak into any of those.
 *
 * The scheme and host it reads off the request are only trustworthy once
 * TrustProxies (bootstrap/app.php) has already honoured X-Forwarded-Proto and
 * X-Forwarded-Host for it -- this middleware must run after that, which is
 * the default Laravel middleware ordering (TrustProxies is global, prepended
 * ahead of every named group).
 */
class ForceRootUrlFromRequest
{
    public function __construct(private readonly bool $appUrlIsUnset) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->appUrlIsUnset) {
            URL::forceRootUrl($request->getSchemeAndHttpHost());

            // Not redundant, which is what an earlier version of this
            // assumed. forceRootUrl() sets the root, but
            // UrlGenerator::formatRoot() then re-applies a scheme from
            // formatScheme(), which reads the generator's OWN request rather
            // than the one this middleware was handed -- so the https:// just
            // written into the root gets overwritten with whatever that
            // request reports. In production the two are normally the same
            // object and it would happen to agree; relying on that is
            // inheriting a framework default instead of stating the
            // semantic, which decision/0010 rules out. State it.
            URL::forceScheme($request->getScheme());
        }

        return $next($request);
    }
}

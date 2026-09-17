<?php

use App\Http\Middleware\EnsurePublicSignupEnabled;
use App\Http\Middleware\ForceRootUrlFromRequest;
use App\Http\Middleware\RequireInstanceSetup;
use App\Support\TrustedProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // doccum ships as one container (spec §13) that self-hosters put
        // behind their own reverse proxy -- Caddy, nginx, Traefik -- which
        // terminates TLS. FrankenPHP inside the container never sees that
        // TLS connection, only the proxy's own plain-HTTP hop to it, so
        // whether X-Forwarded-Proto/-Host/-For/-Port are honoured decides
        // whether every generated URL (the login form's action, @vite's
        // asset URLs, a mailed password-reset link) comes out as https:// on
        // the operator's real domain or http:// on the container's own.
        //
        // The default below is deliberately NOT "*". Trusting every
        // connecting address unconditionally would let anyone who can reach
        // the container at all -- and nothing in this image enforces that
        // only a fronting proxy can: compose.yaml and `docker run -p
        // 8080:8080` both publish the port directly, with no bundled proxy
        // in front to misconfigure or skip -- forge X-Forwarded-For and walk
        // straight through FortifyServiceProvider's per-IP login/passkey
        // throttles (RateLimiter::for('login', ...) keys on $request->ip()),
        // or forge X-Forwarded-Host to point a genuine password-reset email
        // at an attacker's domain: textbook host-header poisoning. Trusting
        // nothing by default is also wrong, though: it is how this
        // container is actually reached in every deployment CLAUDE.md and
        // docker/ describe, so that default would ship every install broken
        // behind the reverse proxy that is the normal case, not the
        // exception.
        //
        // So the default instead trusts only private/loopback ranges: the
        // addresses a fronting proxy actually connects from when it is a
        // sibling container on the same Docker network (the default bridge
        // is 172.17.0.0/16, a Compose network typically 172.18.0.0/16
        // upward -- both inside 172.16.0.0/12) or reaches the published port
        // over the host's own loopback. A request that arrives from a real
        // public address -- the proxy missing, or the port exposed past it
        // by mistake -- never matches, so it gets its own IP and scheme
        // used, not a spoofed one. TRUSTED_PROXIES overrides this: "*"
        // trusts every connecting address (for an operator who already
        // terminates TLS at a cloud load balancer that sets these headers
        // itself), a comma-separated list of IPs/CIDRs trusts exactly those,
        // and an explicit empty string trusts none.
        // Reading the environment lives in TrustedProxies::fromEnvironment();
        // see its docblock for why it does not go through config() here.
        $middleware->trustProxies(
            at: TrustedProxies::fromEnvironment(),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Must run after TrustProxies (the default, global middleware
        // ordering already puts it first) so the scheme/host it reads off
        // the request are the trusted, forwarded ones.
        $middleware->appendToGroup('web', ForceRootUrlFromRequest::class);
        $middleware->appendToGroup('web', RequireInstanceSetup::class);
        $middleware->appendToGroup('web', EnsurePublicSignupEnabled::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

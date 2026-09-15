<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePublicSignupEnabled
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('register', 'register.store') && $this->settings->get('auth.public_signup') !== true) {
            abort(404);
        }

        return $next($request);
    }
}

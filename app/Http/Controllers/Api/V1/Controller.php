<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller as BaseController;
use Illuminate\Validation\ValidationException;

/**
 * Shared plumbing for every /api/v1 controller: the standard validation
 * error shape spec §11 asks for.
 *
 * THE REACH POSTURE MOVED UP, to App\Http\Controllers\Controller, and this
 * class inherits it unchanged -- user(), access(), viewableDirectoryOrFail()
 * and viewableFileOrFail() are all still available here and still mean
 * exactly what they meant when they were written here. They moved because
 * item/reach-oracle-route-binding needed the same lookups for the WEB
 * controllers, where a Model-type-hinted route parameter is resolved by
 * implicit binding before any policy runs and leaks the same existence
 * oracle issue #109 names. Two copies of the settled posture would have been
 * two places for it to drift.
 *
 * Note what did NOT change and is load-bearing: every /api/v1 route declares
 * `int $file` / `int $directory` rather than a Model, so implicit binding
 * never runs on this surface at all. That is why the API was never a site
 * for item/reach-oracle-route-binding, and it is also why a global
 * Route::bind() was rejected as the fix for the web ones -- that binder is
 * keyed on parameter NAME, not type, so it would have substituted a File
 * model into every `int $file` signature below (decision/0114).
 */
abstract class Controller extends BaseController
{
    /**
     * Laravel's standard validation error shape (spec §11's own "Conventions"
     * paragraph), for a domain exception an Action throws AFTER the request
     * already passed field-level validation -- a duplicate name, an
     * archived period, a mismatched upload_id. Every controller below
     * catches those exceptions the same way every Livewire component in
     * this codebase already does (CLAUDE.md's Actions-never-authorise
     * seam has the same shape for errors: the Action raises a typed
     * exception and trusts the caller to decide how it surfaces).
     */
    protected function fail(string $message, string $field = 'error'): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}

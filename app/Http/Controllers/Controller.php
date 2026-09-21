<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Directory;
use App\Models\File;
use App\Models\User;
use App\Services\DirectoryAccess;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;

/**
 * The settled reach posture (issue #109), in one place for every controller.
 *
 * These lookups live here rather than on Api\V1\Controller, where they were
 * written, because the web controllers need exactly the same thing and for
 * exactly the same reason. item/reach-oracle-route-binding: a route
 * parameter type-hinted as a Model is resolved by IMPLICIT BINDING before
 * any policy runs, so the router answers "does this id exist" and a later
 * authorize() answers "may you reach it" -- 404 for a nonexistent id, 403
 * for one that exists outside the caller's reach. That difference is an
 * existence oracle. Declaring the parameter `int` and resolving it through
 * one of these instead is what closes it.
 *
 * Every lookup is scoped to DirectoryAccess::viewableDirectoryIds() INSIDE
 * the query, never resolved with a bare findOrFail() and left for a later
 * authorize() to refuse. A directory or file wholly outside the caller's
 * reach 404s, indistinguishable from a nonexistent id. A directory or file
 * the caller CAN at least view but lacks the permission or access LEVEL for
 * still 403s -- its existence is not a secret from someone who can already
 * see it, so hiding that refusal behind 404 would prove nothing and cost
 * the caller a legible error. Callers still finish the job with their own
 * `$this->authorize(...)` against the same Policy the UI uses.
 */
abstract class Controller
{
    use AuthorizesRequests;

    protected function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function access(): DirectoryAccess
    {
        return app(DirectoryAccess::class);
    }

    /**
     * A directory scoped to what the caller may at least view. Not found --
     * including "exists, but wholly outside the caller's reach" -- 404s.
     */
    protected function viewableDirectoryOrFail(int $id): Directory
    {
        return Directory::query()
            ->whereIn('id', $this->access()->viewableDirectoryIds($this->user()))
            ->findOrFail($id);
    }

    /**
     * A file scoped to what the caller may at least view, THROUGH its
     * directory -- a file has no access level of its own (spec §5). Not
     * found -- including a file whose directory the caller cannot even
     * view -- 404s, same as above.
     *
     * A TRASHED file 404s too, and that is unchanged rather than new:
     * File::query() carries the SoftDeletes global scope, exactly as the
     * implicit binding this replaces did.
     */
    protected function viewableFileOrFail(int $id): File
    {
        return File::query()
            ->whereIn('directory_id', $this->access()->viewableDirectoryIds($this->user()))
            ->findOrFail($id);
    }
}

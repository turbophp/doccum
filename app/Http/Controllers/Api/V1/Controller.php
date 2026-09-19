<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Directory;
use App\Models\File;
use App\Models\User;
use App\Services\DirectoryAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Shared plumbing for every /api/v1 controller: the settled 404 posture
 * (issue #109) and the standard validation error shape spec §11 asks for.
 *
 * Every lookup below is scoped to DirectoryAccess::viewableDirectoryIds()
 * INSIDE the query, never resolved with a bare findOrFail() and left for a
 * later authorize() call to refuse -- that is the exact leak issue #109
 * names, and it is settled the same way here as
 * App\Livewire\Files\Browser::moveFile()/moveDirectory() (see that class's
 * own updated docblocks): a directory or file wholly outside the caller's
 * reach 404s, indistinguishable from a nonexistent id. A directory or file
 * the caller CAN at least view but lacks the permission or access LEVEL for
 * (view-only where the route needs edit, or no Spatie permission at all)
 * still 403s -- its existence is not a secret from a caller who can already
 * see it, so hiding that refusal behind 404 would prove nothing and cost the
 * caller a legible error. Every controller method below still finishes the
 * job with its own `$this->authorize(...)` call against the SAME Policy the
 * UI uses, for that second half.
 */
abstract class Controller extends BaseController
{
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
     */
    protected function viewableFileOrFail(int $id): File
    {
        return File::query()
            ->whereIn('directory_id', $this->access()->viewableDirectoryIds($this->user()))
            ->findOrFail($id);
    }

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

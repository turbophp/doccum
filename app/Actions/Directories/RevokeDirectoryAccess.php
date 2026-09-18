<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Models\DirectoryGrant;

/**
 * Revokes one grant.
 *
 * Authorisation is the CALLER's job -- see GrantDirectoryAccess's docblock
 * for why, and it applies here identically: the caller must have already
 * resolved and authorised the SPECIFIC $grant passed in (through
 * DirectoryPolicy::manageAccess() on the directory it belongs to), because
 * this action does not, and must not, decide that itself (CLAUDE.md: actions
 * never authorise).
 *
 * ->delete() on the retrieved model instance, never a mass
 * `DirectoryGrant::query()->delete()` -- see GrantDirectoryAccess's docblock
 * for why that distinction matters: only the model-instance delete fires
 * DirectoryGrant::deleted(), which is what DoccumServiceProvider uses to
 * flush DirectoryAccess's memoisation.
 */
class RevokeDirectoryAccess
{
    public function handle(DirectoryGrant $grant): void
    {
        // MUTATION: the revoke is a no-op.
    }
}

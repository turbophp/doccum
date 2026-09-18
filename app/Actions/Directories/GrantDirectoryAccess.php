<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\User;

/**
 * Grants a user access to a directory, or raises/lowers an existing grant
 * to a new level -- never a second row for the same (directory, grantee),
 * which is what would otherwise make RevokeDirectoryAccess ambiguous about
 * which row "the" grant means.
 *
 * Authorisation is the CALLER's job, through DirectoryPolicy::manageAccess()
 * -- this action assumes that has already been decided (CLAUDE.md: actions
 * never authorise).
 *
 * The write goes through the DirectoryGrant MODEL -- ->save() on a real
 * instance, either freshly built or the one already on the row -- never a
 * mass `DirectoryGrant::query()->update(...)`. That distinction is not
 * style here: DoccumServiceProvider wires DirectoryGrant::saved() to flush
 * App\Services\DirectoryAccess's per-request memoisation, and a query
 * builder write fires no model event at all, which would leave that
 * service answering stale access decisions for the rest of the request.
 */
class GrantDirectoryAccess
{
    public function handle(Directory $directory, User $grantee, AccessLevel $level): DirectoryGrant
    {
        $grant = DirectoryGrant::query()
            ->where('directory_id', $directory->getKey())
            ->where('grantee_type', 'user')
            ->where('grantee_id', $grantee->getKey())
            ->first();

        if ($grant === null) {
            $grant = new DirectoryGrant([
                'directory_id' => $directory->getKey(),
                'grantee_type' => 'user',
                'grantee_id' => $grantee->getKey(),
            ]);
        }

        $grant->level = $level;
        $grant->save();

        return $grant->refresh();
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\User;
use App\Services\DirectoryAccess;

/**
 * Delegates every decision to DirectoryAccess. No rules live here: both the UI
 * and the API authorise through these policies, so one place must own them.
 */
class DirectoryPolicy
{
    public function __construct(private readonly DirectoryAccess $access) {}

    public function view(User $user, Directory $directory): bool
    {
        return $this->access->can($user, $directory, AccessLevel::View);
    }

    public function create(User $user, Directory $parent): bool
    {
        return $user->can('directories.create')
            && $this->access->can($user, $parent, AccessLevel::Edit);
    }

    public function update(User $user, Directory $directory): bool
    {
        return $this->access->can($user, $directory, AccessLevel::Edit);
    }

    public function delete(User $user, Directory $directory): bool
    {
        return $user->can('directories.manage')
            && $this->access->can($user, $directory, AccessLevel::Manage);
    }

    /**
     * Moving a directory needs manage on the directory itself, the same
     * capability and level delete requires ("manage" per spec §5 covers
     * moving or deleting the directory itself), plus at least edit on the
     * destination so nothing can be dropped into a subtree beyond the
     * mover's reach. A move to the root has no destination to check.
     */
    public function move(User $user, Directory $directory, ?Directory $newParent): bool
    {
        if (! $user->can('directories.manage')) {
            return false;
        }

        if (! $this->access->can($user, $directory, AccessLevel::Manage)) {
            return false;
        }

        return $newParent === null || $this->access->can($user, $newParent, AccessLevel::Edit);
    }

    public function manageAccess(User $user, Directory $directory): bool
    {
        return $this->access->can($user, $directory, AccessLevel::Manage);
    }
}

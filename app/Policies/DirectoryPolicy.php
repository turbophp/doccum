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

    public function manageAccess(User $user, Directory $directory): bool
    {
        return $this->access->can($user, $directory, AccessLevel::Manage);
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Managing OTHER users is an instance-wide capability rather than one scoped
 * to any directory or file, so every ability here reduces to the same
 * Spatie permission -- the same shape as PropertyDefinitionPolicy. See spec
 * §10 ("Each section gated by its Spatie permission") and issue #18.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, User $target): bool
    {
        return $user->can('users.manage');
    }

    public function delete(User $user, User $target): bool
    {
        return $user->can('users.manage');
    }
}

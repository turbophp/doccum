<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\User;
use RuntimeException;

/**
 * Thrown when an operation would leave nobody holding `users.manage`.
 *
 * A shipped doccum instance is single-admin by construction (issue #18's own
 * analysis): the first-run installer creates exactly one admin, and nothing
 * requires a second. That makes the sole holder of `users.manage` a single
 * point of failure two existing surfaces can reach with no guard at all --
 * App\Livewire\Settings\DeleteUserForm lets the authenticated user delete
 * their OWN account, and a role change through the new admin Users page can
 * strip a role from anyone, including the person doing it. Either one, on a
 * single-admin instance, bricks it: nobody left can reach Settings, reassign
 * a role, or create another admin.
 *
 * User::booted()'s `deleting` hook and App\Actions\Users\SetUserRoles both
 * raise this, the same "the guard belongs where nothing can bypass it" shape
 * App\Exceptions\UsernameWouldBeAmbiguous already argues for on this model --
 * see that class's own docblock.
 */
class LastAdministratorMustRemain extends RuntimeException
{
    public static function forUser(User $user): self
    {
        return new self(
            "Refusing to remove [{$user->username}] from users.manage: they are the last holder of it, and losing it would leave nobody able to administer this instance."
        );
    }
}

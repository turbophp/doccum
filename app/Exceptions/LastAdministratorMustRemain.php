<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\User;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Thrown when an operation would leave nobody holding `users.manage`.
 *
 * A shipped doccum instance is single-admin by construction (issue #18's own
 * analysis): the first-run installer creates exactly one admin, and nothing
 * requires a second. That makes the sole holder of `users.manage` a single
 * point of failure surfaces can reach with no guard at all --
 * App\Livewire\Settings\DeleteUserForm lets the authenticated user delete
 * their OWN account, a role change through the admin Users page can strip a
 * role from anyone including the person doing it, and (item/admin-roles,
 * issue #19) editing a ROLE's own permissions through the admin Roles page
 * can strip `users.manage` from that role entirely, taking it from every
 * user who holds it at once. Any one of these, on a single-admin instance,
 * bricks it: nobody left can reach Settings, reassign a role, or create
 * another admin.
 *
 * User::booting()'s `deleting` hook, App\Actions\Users\SetUserRoles and
 * App\Actions\Roles\SetRolePermissions all raise this, the same "the guard
 * belongs where nothing can bypass it" shape
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

    /**
     * The role-side sibling of forUser() -- see App\Actions\Roles\
     * SetRolePermissions and App\Support\LastAdministrator::
     * wouldBeLostByRevokingFrom(). Kept as a second named constructor
     * rather than bending forUser() to accept either model: the two
     * refusals name a different subject (a role's permission set, not a
     * user's role set) and read wrong swapped into each other's message.
     */
    public static function forRole(Role $role): self
    {
        return new self(
            "Refusing to remove users.manage from the [{$role->name}] role: nobody else in this instance holds it, and losing it would leave nobody able to administer this instance."
        );
    }
}

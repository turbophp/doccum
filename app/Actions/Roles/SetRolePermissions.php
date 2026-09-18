<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Exceptions\LastAdministratorMustRemain;
use App\Support\LastAdministrator;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Replaces a role's permission set with a caller-chosen one, refusing a
 * change that would leave `users.manage` with zero holders anywhere in the
 * instance.
 *
 * item/admin-roles (issue #19), the role-side sibling of
 * App\Actions\Users\SetUserRoles (issue #18): that action guards the
 * invariant when a USER's roles change; this one guards the SAME invariant
 * when a ROLE's permissions change underneath every user who holds it.
 * Exactly as that action's own docblock explains for the user-role pivot,
 * Spatie's HasPermissions::syncPermissions() writes the role_has_permissions
 * pivot table directly and fires no Eloquent model event -- see this item's
 * own investigation, recorded in the PR description, on whether anything
 * else observes a permission sync: nothing does. That makes this action's
 * own correctness the WHOLE of the protection for this path, the same way
 * SetUserRoles is the whole of the protection for a role reassignment.
 *
 * Clause one of item/admin-roles's doneWhen -- "toggling a permission
 * changes what the Policy allows in the same request" -- is about
 * Spatie\Permission\PermissionRegistrar's cache, and it is satisfied by the
 * PACKAGE rather than by anything here: syncPermissions() forgets that cache
 * itself. See the note beside the call below for why this action does not
 * forget it a second time, and why removing the redundant call makes the
 * test that covers the clause mean more, not less.
 *
 * This action does NOT authorise -- CLAUDE.md: actions never authorise,
 * callers do. App\Livewire\Admin\Roles is the only caller, and it checks
 * `users.manage` through both its route middleware and its own
 * $this->authorize() call in mount().
 */
class SetRolePermissions
{
    /**
     * @param  array<int, string>  $permissions
     */
    public function handle(Role $role, array $permissions): void
    {
        if (LastAdministrator::wouldBeLostByRevokingFrom($role, $permissions)) {
            throw LastAdministratorMustRemain::forRole($role);
        }

        // syncPermissions() forgets PermissionRegistrar's cache itself --
        // spatie/laravel-permission 8.3.0, HasPermissions::syncPermissions()
        // ends in $this->forgetCachedPermissions(). This action deliberately
        // does NOT call it again.
        //
        // An explicit call here was written first, described as clause one of
        // the doneWhen, and `guards` refused it: the named test passes with
        // that call deleted, because the package had already done the work.
        // CLAUDE.md is exact about what that is -- a guard whose test passes
        // without it is worse than none, because it looks protected.
        //
        // Removing it is also the more INFORMATIVE choice, not merely the
        // tidier one. With our own call in place the test would keep passing
        // even if a future version of the package stopped forgetting, hiding
        // that change behind our redundancy. Without it, the test tracks the
        // behaviour this action actually depends on and goes red on the
        // upgrade that breaks it -- which is the moment someone needs to know.
        DB::transaction(function () use ($role, $permissions): void {
            $role->syncPermissions($permissions);
        });
    }
}

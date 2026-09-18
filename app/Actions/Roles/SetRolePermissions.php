<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Exceptions\LastAdministratorMustRemain;
use App\Support\LastAdministrator;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

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
 * Spatie\Permission\PermissionRegistrar's cache, not about this guard.
 * forgetCachedPermissions() below is not housekeeping: it is the entire
 * reason a can()/Gate check made LATER IN THE SAME REQUEST sees the new
 * permission set at all, rather than the cached one read before this write.
 * database/seeders/RolesAndPermissionsSeeder::run() calls the same method
 * for the same reason, and is the precedent this follows.
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

        DB::transaction(function () use ($role, $permissions): void {
            $role->syncPermissions($permissions);
        });

        // See the class docblock: this is clause one of the doneWhen, not
        // cleanup. Forgotten AFTER the transaction commits, so a concurrent
        // reader can never observe a forgotten cache paired with the old,
        // not-yet-committed permission set.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

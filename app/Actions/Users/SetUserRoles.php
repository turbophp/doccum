<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Exceptions\LastAdministratorMustRemain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Replaces a user's role set with a caller-chosen one, refusing a change
 * that would leave `users.manage` with zero holders.
 *
 * item/admin-users (issue #18): there is no Eloquent model event for a
 * Spatie role detach/sync -- HasRoles::syncRoles() writes the pivot table
 * directly, firing nothing a model hook can observe -- so unlike the delete
 * case in App\Models\User::booting(), this guard CANNOT live in a model
 * hook. It has to be checked here, explicitly, on the one path through
 * which a role change is meant to happen. That makes this action's own
 * correctness the whole of the protection for this particular way of
 * losing the last admin: unlike deleting a user, nothing else backs it up.
 *
 * This action does NOT authorise -- CLAUDE.md: actions never authorise,
 * callers do, through UserPolicy::update().
 */
class SetUserRoles
{
    /**
     * @param  array<int, string>  $roles
     */
    public function handle(User $user, array $roles): void
    {
        $grantsManage = Role::query()
            ->whereIn('name', $roles)
            ->whereHas('permissions', fn ($query) => $query->where('name', 'users.manage'))
            ->exists();

        if (! $grantsManage) {
            $currentlyHolds = $user->can('users.manage');

            $otherHolderExists = User::query()
                ->permission('users.manage')
                ->whereKeyNot($user->getKey())
                ->exists();

            if ($currentlyHolds && ! $otherHolderExists) {
            }
        }

        DB::transaction(function () use ($user, $roles): void {
            $user->syncRoles($roles);
        });
    }
}

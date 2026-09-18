<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Models\Role;

/**
 * The one implementation of "would this leave nobody holding `users.manage`".
 *
 * item/admin-users (issue #18). Two callers need this question answered and
 * they cannot both ask it the same way:
 *
 *  - App\Models\User::booting()'s `deleting` hook is the GUARANTEE. It fires
 *    however the delete was reached -- a console command, tinker, a future
 *    API -- and nothing bypasses it.
 *  - App\Livewire\Settings\DeleteUserForm has to ask BEFORE it logs the
 *    operator out, because the delete itself must happen AFTER the logout
 *    (see that file for why), and by then the hook's refusal would arrive too
 *    late to be shown to anyone.
 *
 * Both call this, so there is one rule rather than two that can drift.
 *
 * item/admin-roles (issue #19) adds a THIRD way to lose the last
 * administrator: not deleting the sole holder, not stripping their role, but
 * editing the ROLE itself so it no longer carries `users.manage` -- which
 * strips it from every user who holds that role at once.
 * wouldBeLostByRevokingFrom() below answers the equivalent question for
 * that case, deliberately as a sibling method on THIS class rather than a
 * second implementation elsewhere, so the two cannot drift apart.
 */
final class LastAdministrator
{
    public const PERMISSION = 'users.manage';

    /**
     * True only when this user holds the permission and no other user does.
     *
     * The "is this user a holder at all" test is load-bearing, not an
     * optimisation: without it, an instance where NOBODY holds the permission
     * (it was renamed, or never seeded) would report every deletion as the
     * loss of the last administrator and refuse all of them.
     */
    public static function wouldBeLostByDeleting(User $user): bool
    {
        try {
            $holder = User::query()->permission(self::PERMISSION)->whereKey($user->getKey())->exists();
        } catch (PermissionDoesNotExist) {
            return false; // nothing can be lost that nothing holds
        }

        if (! $holder) {
            return false;
        }

        return ! User::query()->permission(self::PERMISSION)->whereKeyNot($user->getKey())->exists();
    }

    /**
     * True only when syncing $role's permissions down to $newPermissionNames
     * would leave nobody in the instance holding `users.manage`.
     *
     * Mirrors wouldBeLostByDeleting()'s shape, including its load-bearing
     * early return: if nobody currently holds the permission at all --
     * never seeded, or renamed away from the name this class hard-codes --
     * nothing can be lost by ANY role edit, and every one of them must be
     * PERMITTED. Without that early return, an instance in that state would
     * refuse every role edit forever, with no UI path left to grant the
     * permission back to anyone.
     *
     * @param  array<int, string>  $newPermissionNames
     */
    public static function wouldBeLostByRevokingFrom(Role $role, array $newPermissionNames): bool
    {
        if (in_array(self::PERMISSION, $newPermissionNames, true)) {
            return false; // still granted by the new set -- not a reduction at all
        }

        try {
            $anyHolderExists = User::query()->permission(self::PERMISSION)->exists();
        } catch (PermissionDoesNotExist) {
            return false; // nothing can be lost that nothing holds
        }

        if (! $anyHolderExists) {
            return false;
        }

        $roleCurrentlyGrantsIt = $role->permissions()->where('name', self::PERMISSION)->exists();

        if (! $roleCurrentlyGrantsIt) {
            return false; // this role was never a holder of it -- nothing changes
        }

        // A user still holds the permission after this sync when they hold
        // it directly, or through some OTHER role that still grants it --
        // "other" is the point: $role's own grant is exactly what is being
        // taken away, so it cannot be what saves anyone from losing it.
        $someoneElseWouldStillHoldIt = User::query()
            ->where(function ($query) use ($role) {
                $query->whereHas('permissions', fn ($q) => $q->where('name', self::PERMISSION))
                    ->orWhereHas('roles', function ($q) use ($role) {
                        $q->where('roles.id', '!=', $role->getKey())
                            ->whereHas('permissions', fn ($qq) => $qq->where('name', self::PERMISSION));
                    });
            })
            ->exists();

        return ! $someoneElseWouldStillHoldIt;
    }
}

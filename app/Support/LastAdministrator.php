<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

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
}

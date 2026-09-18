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

    /**
     * Gated on BOTH layers, like move(), delete() and restore() beside it,
     * and unlike how this ability shipped.
     *
     * It previously asked only for Manage on the directory. That is the one
     * sibling of "grant/revoke access, move or delete the directory itself"
     * -- spec §5's own wording, one capability covering all three -- that
     * skipped the permission gate, and it is the most dangerous of the three
     * to leave open, because granting access is how every other restriction
     * gets handed to someone else.
     *
     * The reach was not theoretical: MEMBER_PERMISSIONS carries no
     * directories.manage, and spec §5 also says every user holds manage on
     * their own home directory through an ordinary grant. So every ordinary
     * member could hand anyone any level on their own subtree while being
     * refused the move and delete that the same sentence of the spec groups
     * with it. It was unreachable only because nothing in the UI called this
     * ability; item/directory-access-ui is what would have made it live,
     * which is why it is fixed here rather than filed.
     *
     * Nothing is taken away by this: with no UI, no one could grant anything
     * through it. What it does decide is that sharing is an administrator's
     * act in v1. Whether an ordinary member should be able to share their
     * OWN home directory is a real product question and is filed separately
     * -- it wants a permission of its own (members hold it, it gates only
     * grant/revoke), not the removal of a layer.
     */
    public function manageAccess(User $user, Directory $directory): bool
    {
        return
            // The permission clause carries this comment INSIDE the return
            // expression, and both facts are deliberate. Unique, because the
            // clause alone is textually identical to the ones in restore()
            // and delete() and mutation-check.php matches by substring, so a
            // bare anchor would hit three sites and prove nothing about any
            // of them. Inside, because deleting comment-plus-clause must
            // leave `return $this->access->can(...)` -- valid code with the
            // permission layer gone. Anchoring above the `return` would
            // instead leave a statement with no return at all, and the
            // mutation would "fail" as a TypeError, which tests that PHP has
            // types rather than that this test catches a missing layer.
            $user->can('directories.manage')
            && $this->access->can($user, $directory, AccessLevel::Manage);
    }

    /**
     * Restoring is undoing delete() (a directory is only ever soft-deleted,
     * so delete() is trashing it), so it is gated the same shape: the same
     * capability and the same level -- whoever may hide the subtree may
     * bring it back.
     */
    public function restore(User $user, Directory $directory): bool
    {
        return $user->can('directories.manage')
            && $this->access->can($user, $directory, AccessLevel::Manage);
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\File;
use App\Models\User;
use App\Services\DirectoryAccess;

/**
 * A file's own directory can be trashed out from under it: TrashDirectory
 * cascades a soft delete onto every descendant directory and file, and
 * Directory uses SoftDeletes, so the default (non-trashed) query for a
 * file's directory_id then finds nothing for a file whose directory is
 * part of a trashed subtree. Every method below resolves the directory
 * through an explicit query rather than $file->directory -- the cached
 * belongsTo relation -- for the same reason: a $file instance loaded before
 * its directory was trashed would otherwise keep answering with the stale,
 * pre-trash directory for as long as that instance lives.
 *
 * Two helpers below do the resolving: liveDirectory() finds nothing for a
 * trashed directory, directoryEvenIfTrashed() finds it regardless. Every
 * method decides on purpose which one it wants, rather than one rule for
 * all of them. view(), update(), move(), legalHold() and replace() use
 * liveDirectory() and refuse outright when it comes back null: a trashed
 * subtree is already invisible to browsing and search
 * (DirectoryAccess::resolveViewable()), and resolving withTrashed() in any
 * of these five would reopen exactly the reveal that hides. restore(),
 * delete() and purge() use directoryEvenIfTrashed() and let DirectoryAccess
 * decide as usual, because all three are how a file gets out of a trashed
 * subtree for good, one way or the other, and a blanket refusal would make a
 * cascade-trashed file permanently unrecoverable and unpurgeable. See issue
 * #62 and spec §5.
 */
class FilePolicy
{
    public function __construct(private readonly DirectoryAccess $access) {}

    public function view(User $user, File $file): bool
    {
        $directory = $this->liveDirectory($file);

        if ($directory === null) {
            // A trashed directory is already invisible to browsing and
            // search (DirectoryAccess::resolveViewable()); viewing a file
            // inside it one at a time must not become a side door around
            // that. See the class docblock.
            return false;
        }

        return $this->access->can($user, $directory, AccessLevel::View);
    }

    public function download(User $user, File $file): bool
    {
        return $this->view($user, $file);
    }

    public function create(User $user, Directory $directory): bool
    {
        return $user->can('files.upload')
            && $this->access->can($user, $directory, AccessLevel::Edit);
    }

    public function update(User $user, File $file): bool
    {
        $directory = $this->liveDirectory($file);

        if ($directory === null) {
            // Editing metadata in place on a file inside a trashed subtree
            // is the same reveal-and-modify that view() refuses. See the
            // class docblock.
            return false;
        }

        return $this->access->can($user, $directory, AccessLevel::Edit);
    }

    /**
     * Replacing a file uploads a new version under the file's existing name,
     * so it is gated exactly like a fresh upload into the file's own
     * directory: files.upload plus edit reach, nothing more and nothing
     * less. Delegating to create() rather than restating those two checks
     * follows the download() -> view() precedent already above -- a third
     * condition create() grows later cannot then drift out of step with
     * Replace, which would happen the moment the two checks were
     * duplicated by hand.
     *
     * The liveDirectory() refusal below matches view()/update()/move()/
     * legalHold(): writing a new object into a file nobody can see would
     * either come back resurrected blind on restore, or sit there until
     * purged with nobody the wiser. Restore-then-replace is the sanctioned
     * path, the same argument move()'s own comment already makes for
     * moving rather than reaching into a trashed subtree directly.
     *
     * The trashed() refusal just below is separate on purpose, and it is
     * about the FILE, not its directory: StoreFileVersion::handle() looks
     * a file up with the default (non-trashed) query, so calling it for a
     * file that is independently trashed -- its OWN directory still live --
     * would find no row and CREATE A FRESH FILE with the same name
     * (StoreFileVersionTest's "does not resurrect a trashed file of the
     * same name" proves that is the action's designed behaviour, not a
     * bug this policy should paper over). A later RestoreFile would then
     * collide with that fresh file on the name. Refusing replace() outright
     * for a trashed file forces restore-then-replace here too.
     */
    public function replace(User $user, File $file): bool
    {
        if ($file->trashed()) {
            return false;
        }

        $directory = $this->liveDirectory($file);

        if ($directory === null) {
            // Same refusal as view()/update()/move()/legalHold(): a trashed
            // subtree is already invisible to browsing and search, and
            // writing a new version into a file nobody can see would come
            // back resurrected blind on restore. See the class docblock.
            return false;
        }

        return $this->create($user, $directory);
    }

    /**
     * Trashing a file is gated the same shape as restoring it (see
     * restore()), and resolved the same way, for the same reason: a file
     * can be trashed on its own, independently of its directory (see
     * TrashFile), so a file that was already trashed before its directory
     * was later cascade-trashed by TrashDirectory must still be
     * answerable. delete() and restore() are answering the exact same
     * "edit reach into this file's directory" question from opposite
     * directions, so they must agree -- refusing delete() outright while
     * restore() withTrashed()s would let a file be un-deletable in a state
     * it could still be restored from.
     */
    public function delete(User $user, File $file): bool
    {
        if (! $user->can('files.delete')) {
            return false;
        }

        $directory = $this->directoryEvenIfTrashed($file);

        if ($directory === null) {
            return false;
        }

        return $this->access->can($user, $directory, AccessLevel::Edit);
    }

    /**
     * Permanently deleting a trashed file needs files.delete, the same
     * capability as trashing it, but manage rather than edit on its
     * directory: trashing (see delete()) is reversible, this destroys
     * objects and rows for good, and the bar for reach into the directory
     * is the highest level DirectoryAccess grants. See PurgeFile and spec
     * §9.
     *
     * Resolved the same way as delete() and restore() --
     * directoryEvenIfTrashed(), withTrashed() -- for the same reason: a
     * file cascade-trashed alongside its own directory must still be
     * purgeable, not stranded because its directory query now finds
     * nothing. See the class docblock.
     */
    public function purge(User $user, File $file): bool
    {
        if (! $user->can('files.delete')) {
            return false;
        }

        $directory = $this->directoryEvenIfTrashed($file);

        if ($directory === null) {
            return false;
        }

        return $this->access->can($user, $directory, AccessLevel::Manage);
    }

    /**
     * Restoring is undoing a soft delete, so it is gated the same shape as
     * delete: the files.restore capability, plus edit on the directory the
     * file would reappear in.
     *
     * The directory is resolved withTrashed() rather than through
     * liveDirectory(), on purpose: the ordinary case this guards is a file
     * cascade-trashed alongside its own directory by TrashDirectory, and
     * refusing outright there would make it unrecoverable.
     * DirectoryAccess::hasTrashedProperAncestor() already carves out
     * exactly this shape for a directory checked against itself -- a
     * trashed node answers for access resolved ON it, only a trashed node
     * ABOVE it still blocks -- so resolving withTrashed() here reuses that
     * rule rather than duplicating it. A file whose directory is the
     * trashed batch's own root is answerable; one nested deeper under a
     * trashed ancestor is not, the same as restoring the directory itself
     * must happen at the top of the cascade, not a leaf inside it.
     */
    public function restore(User $user, File $file): bool
    {
        if (! $user->can('files.restore')) {
            return false;
        }

        $directory = $this->directoryEvenIfTrashed($file);

        if ($directory === null) {
            return false;
        }

        return $this->access->can($user, $directory, AccessLevel::Edit);
    }

    /**
     * Moving a file needs edit on both ends: the directory it is leaving and
     * the directory it would land in. Rename and set-properties (see
     * update()) need only edit on the one directory they stay in; a move
     * additionally touches the destination.
     */
    public function move(User $user, File $file, Directory $newDirectory): bool
    {
        $directory = $this->liveDirectory($file);

        if ($directory === null) {
            // move() assumes a live file in a live tree, the same as
            // view() and update(); restoring it out of a trashed subtree is
            // restore()'s job, done before a move rather than instead of
            // one. See the class docblock.
            return false;
        }

        return $this->access->can($user, $directory, AccessLevel::Edit)
            && $this->access->can($user, $newDirectory, AccessLevel::Edit);
    }

    /**
     * Placing or lifting a legal hold needs both layers: the periods.manage
     * capability, because a hold is a period-purging concern, and at least
     * view reach into the file's directory, because a capability holder
     * with no reach into a directory must still learn nothing about what is
     * in it. See spec §9.
     */
    public function legalHold(User $user, File $file): bool
    {
        if (! $user->can('periods.manage')) {
            return false;
        }

        $directory = $this->liveDirectory($file);

        if ($directory === null) {
            // Resolving withTrashed() here would turn the hold check itself
            // into the side channel the view-reach requirement above exists
            // to close: a trashed directory is hidden from view precisely
            // so a capability holder cannot learn what is in it, and legal
            // hold must not carve out an exception to that. See the class
            // docblock.
            return false;
        }

        return $this->access->can($user, $directory, AccessLevel::View);
    }

    /**
     * The file's directory, or null if it is trashed (or gone). Used by
     * view(), update(), move(), legalHold() and replace(), which must all
     * refuse outright rather than see into a trashed subtree. See the class
     * docblock for why delete(), restore() and purge() use
     * directoryEvenIfTrashed() instead.
     */
    private function liveDirectory(File $file): ?Directory
    {
        return Directory::query()->find($file->directory_id);
    }

    /**
     * The file's directory, trashed or not. Used only by delete(),
     * restore() and purge(), which must still be able to answer for a file
     * whose directory was cascade-trashed alongside it.
     */
    private function directoryEvenIfTrashed(File $file): ?Directory
    {
        return Directory::withTrashed()->find($file->directory_id);
    }
}

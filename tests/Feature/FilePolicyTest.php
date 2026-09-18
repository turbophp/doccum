<?php

declare(strict_types=1);

use App\Actions\Directories\TrashDirectory;
use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->dir = Directory::factory()->create();
    $this->user = User::factory()->create();
    $this->user->assignRole('member');
});

// Named giveAccess() rather than give(): DirectoryPolicyTest.php already
// declares a top-level give() helper, and Pest test files share one global
// PHP namespace, so declaring both under the same name fatals with
// "Cannot redeclare function give()".
function giveAccess(Directory $dir, User $user, AccessLevel $level): void
{
    DirectoryGrant::create([
        'directory_id' => $dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $level,
    ]);
}

it('requires edit to upload into a directory', function () {
    giveAccess($this->dir, $this->user, AccessLevel::View);
    expect($this->user->can('create', [File::class, $this->dir]))->toBeFalse();

    DirectoryGrant::query()->delete();
    giveAccess($this->dir, $this->user, AccessLevel::Edit);
    expect($this->user->can('create', [File::class, $this->dir]))->toBeTrue();
});

it('requires the capability as well as the level', function () {
    // A user with manage on the directory but no files.upload capability.
    $stranger = User::factory()->create();
    giveAccess($this->dir, $stranger, AccessLevel::Manage);

    expect($stranger->can('create', [File::class, $this->dir]))->toBeFalse();
});

it('lets a file inherit its directory access', function () {
    $file = File::factory()->for($this->dir, 'directory')->create();
    expect($this->user->can('view', $file))->toBeFalse();

    giveAccess($this->dir, $this->user, AccessLevel::View);
    expect($this->user->fresh()->can('view', $file))->toBeTrue();
});

it('requires edit to rename a file', function () {
    $file = File::factory()->for($this->dir, 'directory')->create();
    giveAccess($this->dir, $this->user, AccessLevel::View);
    expect($this->user->fresh()->can('update', $file))->toBeFalse();

    DirectoryGrant::query()->delete();
    giveAccess($this->dir, $this->user, AccessLevel::Edit);
    expect($this->user->fresh()->can('update', $file))->toBeTrue();
});

it('requires files.delete as well as edit to trash a file', function () {
    // $this->user carries files.delete via the member role, but has no
    // grant on the directory at all.
    $file = File::factory()->for($this->dir, 'directory')->create();
    expect($this->user->can('delete', $file))->toBeFalse();

    giveAccess($this->dir, $this->user, AccessLevel::Edit);
    expect($this->user->fresh()->can('delete', $file))->toBeTrue();
});

it('requires edit as well as files.delete to trash a file', function () {
    $stranger = User::factory()->create();
    giveAccess($this->dir, $stranger, AccessLevel::Edit);
    $file = File::factory()->for($this->dir, 'directory')->create();

    expect($stranger->can('delete', $file))->toBeFalse();
});

it('requires files.delete as well as manage to purge a file', function () {
    // $this->user carries files.delete via the member role, but has no
    // grant on the directory at all.
    $file = File::factory()->for($this->dir, 'directory')->create();
    expect($this->user->can('purge', $file))->toBeFalse();

    giveAccess($this->dir, $this->user, AccessLevel::Manage);
    expect($this->user->fresh()->can('purge', $file))->toBeTrue();
});

it('requires manage as well as files.delete to purge a file', function () {
    $stranger = User::factory()->create();
    giveAccess($this->dir, $stranger, AccessLevel::Manage);
    $file = File::factory()->for($this->dir, 'directory')->create();

    expect($stranger->can('purge', $file))->toBeFalse();
});

it('requires manage rather than merely edit to purge a file, unlike trashing it', function () {
    // $this->user carries files.delete via the member role, which is
    // already enough to trash (see "requires files.delete as well as edit
    // to trash a file" above). Purging is irreversible, so it holds out
    // for the highest level DirectoryAccess grants, not the edit level
    // trashing accepts.
    $file = File::factory()->for($this->dir, 'directory')->create();
    giveAccess($this->dir, $this->user, AccessLevel::Edit);

    expect($this->user->fresh()->can('delete', $file))->toBeTrue()
        ->and($this->user->fresh()->can('purge', $file))->toBeFalse();

    DirectoryGrant::query()->delete();
    giveAccess($this->dir, $this->user, AccessLevel::Manage);
    expect($this->user->fresh()->can('purge', $file))->toBeTrue();
});

it('requires files.restore as well as edit to restore a file', function () {
    $file = File::factory()->for($this->dir, 'directory')->create();
    expect($this->user->can('restore', $file))->toBeFalse();

    giveAccess($this->dir, $this->user, AccessLevel::Edit);
    expect($this->user->fresh()->can('restore', $file))->toBeTrue();
});

it('requires edit as well as files.restore to restore a file', function () {
    $stranger = User::factory()->create();
    giveAccess($this->dir, $stranger, AccessLevel::Edit);
    $file = File::factory()->for($this->dir, 'directory')->create();

    expect($stranger->can('restore', $file))->toBeFalse();
});

it('requires edit on the destination as well as the source to move a file', function () {
    $other = Directory::factory()->create();
    $file = File::factory()->for($this->dir, 'directory')->create();

    giveAccess($this->dir, $this->user, AccessLevel::Edit);
    expect($this->user->fresh()->can('move', [$file, $other]))->toBeFalse();

    giveAccess($other, $this->user, AccessLevel::Edit);
    expect($this->user->fresh()->can('move', [$file, $other]))->toBeTrue();
});

it('requires edit on the source as well as the destination to move a file', function () {
    $other = Directory::factory()->create();
    $file = File::factory()->for($this->dir, 'directory')->create();

    giveAccess($other, $this->user, AccessLevel::Edit);
    expect($this->user->fresh()->can('move', [$file, $other]))->toBeFalse();
});

// The following cover a file whose directory was cascade-trashed by
// TrashDirectory. Directory uses SoftDeletes too, so the file's plain
// belongsTo resolves to null, and every case below exercises exactly that
// null rather than a mere absence of access. See FilePolicy's class
// docblock and issue #62.

it('refuses to view a file whose directory is cascade-trashed, even with access that would otherwise allow it', function () {
    giveAccess($this->dir, $this->user, AccessLevel::Manage);
    $file = File::factory()->for($this->dir, 'directory')->create();

    app(TrashDirectory::class)->handle($this->dir->fresh());
    $trashedFile = File::withTrashed()->findOrFail($file->id);

    expect($this->user->fresh()->can('view', $trashedFile))->toBeFalse();
});

it('refuses to download a file whose directory is cascade-trashed, the same as view', function () {
    giveAccess($this->dir, $this->user, AccessLevel::Manage);
    $file = File::factory()->for($this->dir, 'directory')->create();

    app(TrashDirectory::class)->handle($this->dir->fresh());
    $trashedFile = File::withTrashed()->findOrFail($file->id);

    expect($this->user->fresh()->can('download', $trashedFile))->toBeFalse();
});

it('refuses to update a file whose directory is cascade-trashed, even with access that would otherwise allow it', function () {
    giveAccess($this->dir, $this->user, AccessLevel::Manage);
    $file = File::factory()->for($this->dir, 'directory')->create();

    // Restored, DIRECTORY left trashed -- the same isolation
    // 'refuses to replace a file whose directory is cascade-trashed' already
    // carries, and for the same reason, which item/file-policy-trashed-file-guards
    // has now made true of this test too. TrashDirectory cascades onto every
    // descendant file, so a plain cascade leaves $file->trashed() true, and
    // update()'s new trashed-FILE guard runs first and refuses on its own. That made
    // this test pass with the liveDirectory() guard deleted, and the mutation
    // harness said so in as many words: "STILL PASSES with the guard deleted.
    // The guard is not load-bearing, or the test does not exercise it."
    //
    // Restoring just the file leaves the directory guard as the only thing that
    // can refuse. It is a reachable state rather than a contrivance: restore(),
    // delete() and purge() resolve the directory withTrashed() on purpose, so a
    // cascade-trashed file can be restored one at a time, which leaves it live
    // beneath a directory that is still trashed.
    app(TrashDirectory::class)->handle($this->dir->fresh());

    $cascaded = File::withTrashed()->findOrFail($file->id);
    $cascaded->restore();

    $live = File::findOrFail($file->id);
    expect($live->trashed())->toBeFalse();

    expect($this->user->fresh()->can('update', $live))->toBeFalse();
});

it('refuses to move a file whose source directory is cascade-trashed, even with access to a live destination', function () {
    $destination = Directory::factory()->create();
    giveAccess($this->dir, $this->user, AccessLevel::Manage);
    giveAccess($destination, $this->user, AccessLevel::Edit);
    $file = File::factory()->for($this->dir, 'directory')->create();

    // Restored, DIRECTORY left trashed -- the same isolation
    // 'refuses to replace a file whose directory is cascade-trashed' already
    // carries, and for the same reason, which item/file-policy-trashed-file-guards
    // has now made true of this test too. TrashDirectory cascades onto every
    // descendant file, so a plain cascade leaves $file->trashed() true, and
    // move()'s new trashed-FILE guard runs first and refuses on its own. That made
    // this test pass with the liveDirectory() guard deleted, and the mutation
    // harness said so in as many words: "STILL PASSES with the guard deleted.
    // The guard is not load-bearing, or the test does not exercise it."
    //
    // Restoring just the file leaves the directory guard as the only thing that
    // can refuse. It is a reachable state rather than a contrivance: restore(),
    // delete() and purge() resolve the directory withTrashed() on purpose, so a
    // cascade-trashed file can be restored one at a time, which leaves it live
    // beneath a directory that is still trashed.
    app(TrashDirectory::class)->handle($this->dir->fresh());

    $cascaded = File::withTrashed()->findOrFail($file->id);
    $cascaded->restore();

    $live = File::findOrFail($file->id);
    expect($live->trashed())->toBeFalse();

    expect($this->user->fresh()->can('move', [$live, $destination]))->toBeFalse();
});

it('refuses a legal hold on a file whose directory is cascade-trashed, even for a periods.manage holder with access', function () {
    $holder = User::factory()->create();
    $holder->assignRole('admin');
    giveAccess($this->dir, $holder, AccessLevel::Manage);
    $file = File::factory()->for($this->dir, 'directory')->create();

    // Restored, DIRECTORY left trashed -- the same isolation
    // 'refuses to replace a file whose directory is cascade-trashed' already
    // carries, and for the same reason, which item/file-policy-trashed-file-guards
    // has now made true of this test too. TrashDirectory cascades onto every
    // descendant file, so a plain cascade leaves $file->trashed() true, and
    // legalHold()'s new trashed-FILE guard runs first and refuses on its own. That made
    // this test pass with the liveDirectory() guard deleted, and the mutation
    // harness said so in as many words: "STILL PASSES with the guard deleted.
    // The guard is not load-bearing, or the test does not exercise it."
    //
    // Restoring just the file leaves the directory guard as the only thing that
    // can refuse. It is a reachable state rather than a contrivance: restore(),
    // delete() and purge() resolve the directory withTrashed() on purpose, so a
    // cascade-trashed file can be restored one at a time, which leaves it live
    // beneath a directory that is still trashed.
    app(TrashDirectory::class)->handle($this->dir->fresh());

    $cascaded = File::withTrashed()->findOrFail($file->id);
    $cascaded->restore();

    $live = File::findOrFail($file->id);
    expect($live->trashed())->toBeFalse();

    expect($holder->fresh()->can('legalHold', $live))->toBeFalse();
});

// restore(), delete() and purge() each carry their own "if ($directory ===
// null) { return false; }" guard after resolving withTrashed() -- see
// FilePolicy::directoryEvenIfTrashed(). None of the three is in
// .github/mutations.json. directory_id is a required, cascade-on-hard-delete
// foreign key, and directories are never hard-deleted (PeriodPurger and
// PurgeFile force-delete files, never directories), so
// directoryEvenIfTrashed() cannot actually return null in this codebase
// today -- there is no way for a test to make it do so, which means no test
// can fail when the guard is removed. Banking an entry that always reports
// "STILL PASSES with the guard deleted" would be exactly the false
// confidence CLAUDE.md's mutation rule exists to prevent. The guard stays
// for defence in depth -- can() would otherwise be handed a null against its
// non-nullable Directory parameter -- but it is not a security boundary
// tested here, unlike the outright refusals below.
it('still allows restore of a file whose directory is cascade-trashed, consulted withTrashed(), when access reaches the trashed directory itself', function () {
    giveAccess($this->dir, $this->user, AccessLevel::Edit);
    $file = File::factory()->for($this->dir, 'directory')->create();

    app(TrashDirectory::class)->handle($this->dir->fresh());
    $trashedFile = File::withTrashed()->findOrFail($file->id);

    expect($this->user->fresh()->can('restore', $trashedFile))->toBeTrue();
});

it('still refuses restore of a file whose cascade-trashed directory the user has no access to, rather than throwing', function () {
    $file = File::factory()->for($this->dir, 'directory')->create();

    app(TrashDirectory::class)->handle($this->dir->fresh());
    $trashedFile = File::withTrashed()->findOrFail($file->id);

    expect($this->user->fresh()->can('restore', $trashedFile))->toBeFalse();
});

it('still allows delete of a file whose directory is cascade-trashed, consulted withTrashed(), when access reaches the trashed directory itself', function () {
    giveAccess($this->dir, $this->user, AccessLevel::Edit);
    $file = File::factory()->for($this->dir, 'directory')->create();

    app(TrashDirectory::class)->handle($this->dir->fresh());
    $trashedFile = File::withTrashed()->findOrFail($file->id);

    expect($this->user->fresh()->can('delete', $trashedFile))->toBeTrue();
});

it('still refuses delete of a file whose cascade-trashed directory the user has no access to, rather than throwing', function () {
    $file = File::factory()->for($this->dir, 'directory')->create();

    app(TrashDirectory::class)->handle($this->dir->fresh());
    $trashedFile = File::withTrashed()->findOrFail($file->id);

    expect($this->user->fresh()->can('delete', $trashedFile))->toBeFalse();
});

it('still allows purge of a file whose directory is cascade-trashed, consulted withTrashed(), when access reaches the trashed directory itself', function () {
    giveAccess($this->dir, $this->user, AccessLevel::Manage);
    $file = File::factory()->for($this->dir, 'directory')->create();

    app(TrashDirectory::class)->handle($this->dir->fresh());
    $trashedFile = File::withTrashed()->findOrFail($file->id);

    expect($this->user->fresh()->can('purge', $trashedFile))->toBeTrue();
});

it('still refuses purge of a file whose cascade-trashed directory the user has no access to, rather than throwing', function () {
    $file = File::factory()->for($this->dir, 'directory')->create();

    app(TrashDirectory::class)->handle($this->dir->fresh());
    $trashedFile = File::withTrashed()->findOrFail($file->id);

    expect($this->user->fresh()->can('purge', $trashedFile))->toBeFalse();
});

it('refuses restore of a file nested beneath the trashed batch root, even with access on its own directory', function () {
    // The grant sits directly on $mid, the file's own directory -- but $mid
    // is not the batch's root, and DirectoryAccess still finds $this->dir
    // (the directory actually trashed) sitting above it as a trashed
    // PROPER ancestor. restore() reuses
    // DirectoryAccess::hasTrashedProperAncestor() rather than special-casing
    // the file's own directory, so this must refuse the same way a
    // manage() check on $mid directly would.
    $mid = Directory::factory()->for($this->dir, 'parent')->create();
    giveAccess($mid, $this->user, AccessLevel::Manage);
    $file = File::factory()->for($mid, 'directory')->create();

    app(TrashDirectory::class)->handle($this->dir->fresh());
    $trashedFile = File::withTrashed()->findOrFail($file->id);

    expect($this->user->fresh()->can('restore', $trashedFile))->toBeFalse();
});

it('refuses delete of a file nested beneath the trashed batch root, even with access on its own directory', function () {
    $mid = Directory::factory()->for($this->dir, 'parent')->create();
    giveAccess($mid, $this->user, AccessLevel::Manage);
    $file = File::factory()->for($mid, 'directory')->create();

    app(TrashDirectory::class)->handle($this->dir->fresh());
    $trashedFile = File::withTrashed()->findOrFail($file->id);

    expect($this->user->fresh()->can('delete', $trashedFile))->toBeFalse();
});

it('refuses purge of a file nested beneath the trashed batch root, even with access on its own directory', function () {
    $mid = Directory::factory()->for($this->dir, 'parent')->create();
    giveAccess($mid, $this->user, AccessLevel::Manage);
    $file = File::factory()->for($mid, 'directory')->create();

    app(TrashDirectory::class)->handle($this->dir->fresh());
    $trashedFile = File::withTrashed()->findOrFail($file->id);

    expect($this->user->fresh()->can('purge', $trashedFile))->toBeFalse();
});

// item/files-versions-replace (issue #102): replace() delegates to create(),
// so it needs both layers create() needs -- files.upload and edit -- plus its
// own two refusals: the file's OWN trashed state (independent of its
// directory) and a cascade-trashed directory. See FilePolicy::replace()'s
// own docblock for why each is separate.
//
// $this->user carries files.upload through the 'member' role assigned in
// this file's top-level beforeEach() (RolesAndPermissionsSeeder::
// MEMBER_PERMISSIONS), so the "no files.upload" case below uses a fresh,
// roleless $stranger instead -- the same shape as "requires the capability
// as well as the level" above, which is create()'s own version of this test.

it('refuses to replace a file whose directory is cascade-trashed, even with access that would otherwise allow it', function () {
    giveAccess($this->dir, $this->user, AccessLevel::Manage);
    $file = File::factory()->for($this->dir, 'directory')->create();

    app(TrashDirectory::class)->handle($this->dir->fresh());

    // The file is restored and the DIRECTORY left trashed, deliberately.
    //
    // TrashDirectory cascades onto every descendant file, so a plain cascade
    // also leaves $file->trashed() true -- and replace()'s trashed-FILE
    // guard, which runs first, would then refuse on its own. This test
    // passed with the liveDirectory() guard deleted for exactly that reason,
    // and the mutation harness said so: "STILL PASSES with the guard
    // deleted. The guard is not load-bearing, or the test does not exercise
    // it." It was the second.
    //
    // Restoring just the file isolates the directory guard as the only thing
    // left that can refuse. It is a reachable state, not a contrivance:
    // restore(), delete() and purge() resolve the directory withTrashed() on
    // purpose (see the FilePolicy class docblock) precisely so a
    // cascade-trashed file can be restored one at a time, which leaves it
    // live beneath a directory that is still trashed.
    $file = File::withTrashed()->findOrFail($file->id);
    $file->restore();

    $live = File::findOrFail($file->id);
    expect($live->trashed())->toBeFalse();

    expect($this->user->fresh()->can('replace', $live))->toBeFalse();
});

it('refuses to replace a trashed file, live directory, full access', function () {
    giveAccess($this->dir, $this->user, AccessLevel::Manage);
    $file = File::factory()->for($this->dir, 'directory')->create();
    $file->delete();

    expect($this->user->fresh()->can('replace', $file->fresh()))->toBeFalse();
});

it('allows replace for a user with files.upload and edit', function () {
    giveAccess($this->dir, $this->user, AccessLevel::Edit);
    $file = File::factory()->for($this->dir, 'directory')->create();

    expect($this->user->fresh()->can('replace', $file))->toBeTrue();
});

it('refuses replace for a user with edit but no files.upload permission, proving the delegation to create() carries both layers', function () {
    $stranger = User::factory()->create();
    giveAccess($this->dir, $stranger, AccessLevel::Edit);
    $file = File::factory()->for($this->dir, 'directory')->create();

    expect($stranger->can('replace', $file))->toBeFalse();
});

// item/file-policy-trashed-file-guards (issue #116): update(), move() and
// legalHold() had a gap replace() already closed -- they resolved the
// file's LIVE-DIRECTORY question (liveDirectory(), see #62) but never asked
// the file's OWN trashed() question at all, independently of its directory.
// Each case below trashes the FILE ALONE via $file->delete() and leaves its
// directory untouched and live -- never TrashDirectory, which cascades onto
// the file and would let the directory guard do the refusing instead of the
// one under test (the exact mistake already made once on replace(), see
// that test's own comment above). Full access and every other capability
// are granted, so the trashed-file guard is the only thing left that can
// refuse.

it('refuses to update a trashed file, live directory, full access', function () {
    giveAccess($this->dir, $this->user, AccessLevel::Manage);
    $file = File::factory()->for($this->dir, 'directory')->create();
    $file->delete();

    expect($this->user->fresh()->can('update', $file->fresh()))->toBeFalse();
});

it('refuses to move a trashed file, live source and destination, full access', function () {
    $destination = Directory::factory()->create();
    giveAccess($this->dir, $this->user, AccessLevel::Manage);
    giveAccess($destination, $this->user, AccessLevel::Manage);
    $file = File::factory()->for($this->dir, 'directory')->create();
    $file->delete();

    expect($this->user->fresh()->can('move', [$file->fresh(), $destination]))->toBeFalse();
});

it('refuses a legal hold on a trashed file, live directory, periods.manage and full access', function () {
    $holder = User::factory()->create();
    $holder->assignRole('admin');
    giveAccess($this->dir, $holder, AccessLevel::Manage);
    $file = File::factory()->for($this->dir, 'directory')->create();
    $file->delete();

    expect($holder->fresh()->can('legalHold', $file->fresh()))->toBeFalse();
});

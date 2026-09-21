<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Livewire\Files\Browser;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * item/files-actions-ui (issue #101): selecting a file or directory row opens
 * the detail panel's Rename, Move, Trash and legal-hold controls, and every
 * one of them is authorised in THIS component through a Policy -- never
 * inside the Action it calls (CLAUDE.md).
 *
 * Every "refuses ..." test below is the expectFailing target for exactly one
 * .github/mutations.json entry -- except 'refuses to select a directory the
 * viewer cannot view', which deliberately has no entry: PropertyPanel::mount()
 * authorises view on the same subject, so that refusal survives deleting
 * selectDirectory()'s own authorize() and the mutation harness said so. The
 * guard stays; the claim that a mutation proves it does not. See the note on
 * selectDirectory() in app/Livewire/Files/Browser.php.
 *
 * For the rest: with the matching `$this->authorize(...)`
 * call removed from app/Livewire/Files/Browser.php, none of the Actions this
 * component calls (RenameFile, MoveFile, TrashFile, SetLegalHold,
 * RenameDirectory, MoveDirectory, TrashDirectory) checks anything on its own
 * -- CLAUDE.md is explicit that they must not -- so the call would go
 * through unchecked and assertForbidden() would fail. That is what makes
 * each of these tests the load-bearing half of its mutation entry.
 */
beforeEach(function () {
    Storage::fake('documents');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('member');

    $this->mine = Directory::factory()->create(['name' => 'Mine']);
    $this->theirs = Directory::factory()->create(['name' => 'Theirs']);

    DirectoryGrant::create([
        'directory_id' => $this->mine->id,
        'grantee_type' => 'user',
        'grantee_id' => $this->user->id,
        'level' => AccessLevel::Manage,
    ]);
});

function viewOnlyMember(Directory $directory): User
{
    $viewer = User::factory()->create();
    $viewer->assignRole('member');

    DirectoryGrant::create([
        'directory_id' => $directory->id,
        'grantee_type' => 'user',
        'grantee_id' => $viewer->id,
        'level' => AccessLevel::View,
    ]);

    return $viewer;
}

// --- selecting a directory row ---------------------------------------------

it('refuses to select a directory the viewer cannot view', function () {
    // $this->user holds Manage on $this->mine only, nothing on $this->theirs,
    // and mount() lets a null $directory through with no check at all
    // (browsing the root) -- so a directory id typed straight into the
    // wire:click reaches selectDirectory() with nothing ahead of it.
    //
    // This asserted 403 until item/reach-existence-oracle. The root lookup
    // used to union whereNull('parent_id') into the candidates, which found
    // an unviewable root and left authorize('view', ...) to refuse it; it is
    // now scoped to reachRootIds() alone, so the row is not found and the
    // answer is 404 -- the same answer an id that does not exist gets. The
    // companion below is what makes that a claim about the PAIR rather than
    // about this case alone.
    Livewire::actingAs($this->user)
        ->test(Browser::class)
        ->call('selectDirectory', $this->theirs->id)
        ->assertNotFound();
});

it('answers a directory id that does not exist the same way as one out of reach', function () {
    $nonexistent = ((int) Directory::query()->max('id')) + 1;

    Livewire::actingAs($this->user)
        ->test(Browser::class)
        ->call('selectDirectory', $nonexistent)
        ->assertNotFound();
});

it('opens the property panel on a selected subdirectory', function () {
    $child = Directory::factory()->for($this->mine, 'parent')->create(['name' => 'Child']);

    $component = Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectDirectory', $child->id)
        ->assertSet('selectedFile', null);

    expect($component->get('selectedDirectory')->id)->toBe($child->id);
});

// --- rename, move, trash a file ---------------------------------------------

it('renames a file through the browser', function () {
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->set('renameValue', 'b.txt')
        ->call('renameFile')
        ->assertHasNoErrors();

    expect($file->fresh()->name)->toBe('b.txt');
});

it('refuses to rename a file without edit access', function () {
    $viewer = viewOnlyMember($this->mine);
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);

    Livewire::actingAs($viewer)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->call('renameFile')
        ->assertForbidden();

    expect($file->fresh()->name)->toBe('a.txt');
});

/**
 * item/file-policy-trashed-file-guards (issue #116), the GUARANTEE half.
 *
 * Whatever Livewire does with a public Model property across a request, a
 * file trashed on its own must not be renameable through the browser. That
 * holds under both of the two possible mechanisms below, so this test is
 * true in both worlds and stays green whichever one CI reports -- it is the
 * security property the item actually owes, and it is deliberately asserted
 * WITHOUT reference to which guard produced it.
 *
 * The file is trashed ALONE, out of band, between two calls on the SAME
 * component instance -- never through the component, and never by trashing
 * $this->mine, which would let a cascade-trashed-directory refusal answer
 * instead (that is #62's guard, not this one, and it is exactly the trap
 * that made an earlier replace() test pass with its guard deleted).
 *
 * Read with File::withTrashed() rather than $file->fresh(), because whether
 * fresh() returns the row or null depends on the very scope question under
 * test -- a null there would fail this test for a reason that has nothing to
 * do with renaming.
 */
it('does not rename a file trashed on its own while its directory stays live', function () {
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);

    $component = Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id);

    $file->delete();

    $component->set('renameValue', 'b.txt')->call('renameFile');

    expect(File::withTrashed()->find($file->id)->name)->toBe('a.txt');
});

/**
 * item/file-policy-trashed-file-guards (issue #116), the DISCRIMINANT half.
 *
 * Same interaction as the guarantee above, asserting the one thing that
 * differs between the two worlds -- because "some error" would settle
 * nothing, and CLAUDE.md is explicit that a proof which holds either way is
 * worse than none, since it looks like proof.
 *
 * The open question is one FilePolicy::replace()'s own trashed-file guard
 * already rested on without anyone checking it: does Livewire's restoration
 * of a public Model property re-query through the model's DEFAULT
 * (SoftDeletingScope-applying) query on each request, the way an implicit
 * route-model binding would, or does it hand back what it last held?
 *
 *   403 means Livewire hands $selectedFile back as the trashed model,
 *       execution reaches $this->authorize('update', ...), and the
 *       trashed() guard added to FilePolicy::update() is what refuses.
 *
 *   404 means the restoration re-queries through the default scope, the
 *       trashed row is not found, $selectedFile comes back null, and
 *       renameFile()'s own abort_if(... === null, 404) answers first -- so
 *       update()'s new guard is unreachable through THIS surface, though it
 *       still answers every direct User::can('update', ...) call, which
 *       FilePolicyTest asserts independently and which is what the
 *       mutations.json entries key off.
 *
 * SETTLED, BY MEASUREMENT: it is 403. This test was written as an open bet
 * -- there is no vendor/ in the agent environment, so Livewire's
 * model-hydration source could not be read -- and CI answered it green on
 * the first run, across five test jobs (PHP 8.4/8.5 x sqlite/pgsql/mysql).
 *
 * So Livewire's restoration does NOT apply the SoftDeletingScope: it hands
 * $selectedFile back as the trashed model, renameFile() reaches
 * $this->authorize('update', ...), and FilePolicy::update()'s trashed()
 * guard is what refuses. Two things follow. Issue #116 was REACHABLE rather
 * than theoretical -- a file trashed by another user between two of this
 * user's requests really would have been renameable before this item. And
 * replace()'s own trashed-file guard, which rested on this same unread
 * fact, was correctly placed.
 *
 * Worth recording that the reasoning went the other way. Both the
 * implementing worker and the design consult argued for 404, from the
 * implicit-route-model-binding analogy and from newQueryForRestoration();
 * the measurement says otherwise. That is the third time in this loop a
 * plausible chain of reasoning about framework internals has been overturned
 * by one CI run, and it is why this item was written to measure rather than
 * argue.
 *
 * If this ever goes red reading "expected 403, got 404", that is a Livewire
 * upgrade changing the answer -- a real finding about the framework, not a
 * broken test. Record it, flip the assertion, and re-check that the
 * guarantee test above still passes, because THAT is the security property;
 * this one is the explanation.
 */
it('settles whether Livewire restoration applies the SoftDeletingScope to a selected file', function () {
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);

    $component = Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id);

    $file->delete();

    $component->set('renameValue', 'b.txt')
        ->call('renameFile')
        ->assertForbidden();
});

it('moves a file through the browser', function () {
    $destination = Directory::factory()->create(['name' => 'Destination']);
    DirectoryGrant::create([
        'directory_id' => $destination->id,
        'grantee_type' => 'user',
        'grantee_id' => $this->user->id,
        'level' => AccessLevel::Edit,
    ]);
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->set('moveFileDestinationId', $destination->id)
        ->call('moveFile')
        ->assertHasNoErrors();

    expect($file->fresh()->directory_id)->toBe($destination->id);
});

/**
 * item/api-content (issue #23), the product-wide 404 posture (issue #109):
 * $viewer holds View on $this->mine and NOTHING AT ALL on $this->theirs --
 * $this->theirs is wholly outside DirectoryAccess::viewableDirectoryIds()
 * for this viewer, not merely below the access level a move needs. Before
 * this item, moveFile() resolved the destination with a bare findOrFail()
 * and let authorize('move', ...) refuse it, which answered 403 -- the same
 * status a destination the viewer COULD see but only at View level would
 * get. That conflated two different facts behind one status code, and is
 * exactly what issue #109 names.
 *
 * moveFile() now scopes that lookup to viewableDirectoryIds() INSIDE the
 * query, so a destination this invisible to the viewer 404s, indistinguishable
 * from a nonexistent id -- this is the ONE test this item's doneWhen asks to
 * flip from 403 to 404 (see this item's own report for why it is one, not
 * the two the doneWhen's own count anticipated: the other move test in this
 * file that resolves an actually-DENIED destination --
 * "offers an edit-reachable move destination and refuses a view-only one"
 * -- uses a destination the mover CAN see at View level, which still falls
 * inside the new scope and so still 403s from authorize(), unchanged).
 */
it('404s moving a file to a destination the viewer cannot even see', function () {
    $viewer = viewOnlyMember($this->mine);
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);

    Livewire::actingAs($viewer)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->set('moveFileDestinationId', $this->theirs->id)
        ->call('moveFile')
        ->assertNotFound();

    expect($file->fresh()->directory_id)->toBe($this->mine->id);
});

it('offers an edit-reachable move destination and refuses a view-only one', function () {
    $editable = Directory::factory()->create(['name' => 'Editable']);
    DirectoryGrant::create([
        'directory_id' => $editable->id,
        'grantee_type' => 'user',
        'grantee_id' => $this->user->id,
        'level' => AccessLevel::Edit,
    ]);

    // Viewable -- it appears in DirectoryAccess::viewableDirectoryIds() --
    // but only at View level, which move() requires strictly more than.
    // Listed here means the whereIn(..., $viewable) query ran; excluded
    // means the destinations were also narrowed to edit-level, which is the
    // part a Blade-only filter could never be trusted to enforce.
    $viewOnly = Directory::factory()->create(['name' => 'ViewOnlyDestination']);
    DirectoryGrant::create([
        'directory_id' => $viewOnly->id,
        'grantee_type' => 'user',
        'grantee_id' => $this->user->id,
        'level' => AccessLevel::View,
    ]);

    $file = File::factory()->for($this->mine, 'directory')->create();

    // The "is offered" half is unchanged: a presence assertion is unaffected
    // by anything else the page renders.
    //
    // The "is not offered" half CANNOT stay a page-wide assertDontSee, and
    // that is item/files-three-pane's doing rather than a weakening. The
    // sidebar now renders every directory the viewer may see on every /files
    // page -- that is the item, and issue #99's fix -- and
    // ViewOnlyDestination is viewable, so it legitimately appears. A
    // page-wide assertDontSee can no longer tell "absent from the move
    // dropdown" from "absent from the page", and no fixture can dodge it:
    // any viewable directory appears in the tree, as a reach root or as a
    // viewable descendant.
    //
    // So the second half asks the stronger question directly -- can a
    // View-level destination actually be moved into? -- which is the thing
    // the narrowing exists to prevent and which no page text can fake. It is
    // also a sharper case than "refuses to move a file without edit access
    // on both ends" below: that one uses a destination the user cannot see
    // at all, where this one sits exactly on the View/Edit boundary.
    //
    // Honestly stated: what is NOT covered any more is the UX-level claim
    // that the dropdown omits the option. The security property is the
    // refusal, and that is what is asserted here.
    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->assertSee('Editable');

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->set('moveFileDestinationId', $viewOnly->id)
        ->call('moveFile')
        ->assertForbidden();

    expect($file->fresh()->directory_id)->toBe($this->mine->id);
});

it('trashes a file through the browser', function () {
    $file = File::factory()->for($this->mine, 'directory')->create();

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->call('trashFile')
        ->assertSet('selectedFile', null);

    expect($file->fresh()->trashed())->toBeTrue();
});

it('refuses to trash a file without edit access', function () {
    $viewer = viewOnlyMember($this->mine);
    $file = File::factory()->for($this->mine, 'directory')->create();

    Livewire::actingAs($viewer)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->call('trashFile')
        ->assertForbidden();

    expect($file->fresh()->trashed())->toBeFalse();
});

// --- replace a file, version history -----------------------------------------

it('replaces a file through the browser', function () {
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);
    FileVersion::factory()->for($file)->create(['version_number' => 1]);
    $file->update(['current_version_id' => $file->versions()->first()->id]);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        // The client name deliberately differs from the document's own name
        // ('a.txt'). This is the unit-level shadow of the container smoke's
        // mutation: StoreFileVersion is handed $selectedFile->name, never
        // this upload's client name, so if that argument were ever swapped
        // for the upload's own name, this would create A SECOND FILE named
        // 'replacement-body.txt' instead of a version of 'a.txt', and the
        // "no File row" assertion below would fail.
        ->set('replacement', UploadedFile::fake()->create('replacement-body.txt', 4))
        ->call('replaceFile')
        ->assertHasNoErrors();

    $file->refresh();

    expect($file->name)->toBe('a.txt')
        ->and($file->versions()->count())->toBe(2)
        ->and($file->currentVersion->version_number)->toBe(2)
        ->and(File::where('name', 'replacement-body.txt')->doesntExist())->toBeTrue();
});

it('lists versions newest first', function () {
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);
    FileVersion::factory()->for($file)->create(['version_number' => 1]);
    $file->update(['current_version_id' => $file->versions()->first()->id]);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->set('replacement', UploadedFile::fake()->create('v2.txt', 4))
        ->call('replaceFile')
        ->assertHasNoErrors()
        // A count assertion cannot distinguish "ordered" from "SQLite
        // happened to return rowid order" -- assertSeeInOrder fails against
        // the unordered default, a plain assertSee for both would not.
        ->assertSeeInOrder(['Version 2', 'Version 1']);
});

it("shows the uploader's name and the version size", function () {
    $uploader = User::factory()->create(['name' => 'Distinctive Uploader Wozniak']);
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);
    $version = FileVersion::factory()->for($file)->create([
        'version_number' => 1,
        'size' => 2048,
        'uploaded_by' => $uploader->id,
        'created_at' => '2026-03-14 00:00:00',
    ]);
    $file->update(['current_version_id' => $version->id]);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->assertSee('Distinctive Uploader Wozniak')
        ->assertSee('2 KB')
        ->assertSee('2026-03-14');
});

it('refuses to replace a file without files.upload', function () {
    // A directory-manage viewer whose ROLE carries no files.upload -- the
    // shape FilePolicyTest's own replace() coverage uses for the same
    // refusal. See viewOnlyMember() just above for why a fresh, roleless
    // user rather than $this->user (a 'member', which does carry
    // files.upload) is what proves this.
    $stranger = User::factory()->create();
    DirectoryGrant::create([
        'directory_id' => $this->mine->id,
        'grantee_type' => 'user',
        'grantee_id' => $stranger->id,
        'level' => AccessLevel::Manage,
    ]);
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);
    FileVersion::factory()->for($file)->create(['version_number' => 1]);
    $file->update(['current_version_id' => $file->versions()->first()->id]);

    Livewire::actingAs($stranger)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->set('replacement', UploadedFile::fake()->create('v2.txt', 4))
        ->call('replaceFile')
        ->assertForbidden();

    expect($file->fresh()->versions()->count())->toBe(1);
});

it('hides the Replace control from a viewer holding view only', function () {
    $viewer = viewOnlyMember($this->mine);
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);
    FileVersion::factory()->for($file)->create(['version_number' => 1]);
    $file->update(['current_version_id' => $file->versions()->first()->id]);

    Livewire::actingAs($viewer)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->assertDontSeeHtml('data-test="replace-file-button"');
});

it('adds an error instead of a 500 when the file\'s period is archived', function () {
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);
    FileVersion::factory()->for($file)->create(['version_number' => 1]);
    $file->update(['current_version_id' => $file->versions()->first()->id]);

    ArchivePeriod::factory()->create([
        'year' => $file->period_year,
        'month' => $file->period_month,
        'archived_at' => now(),
    ]);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->set('replacement', UploadedFile::fake()->create('v2.txt', 4))
        ->call('replaceFile')
        ->assertHasErrors('replacement');

    expect($file->fresh()->versions()->count())->toBe(1);
});

// --- legal hold --------------------------------------------------------------

it('sets and clears a legal hold through the browser', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $file = File::factory()->for($this->mine, 'directory')->create();

    $held = Livewire::actingAs($admin)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->call('setLegalHold', true);

    expect($held->get('selectedFile')->legal_hold)->toBeTrue()
        ->and($file->fresh()->legal_hold)->toBeTrue();

    $lifted = Livewire::actingAs($admin)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->call('setLegalHold', false);

    expect($lifted->get('selectedFile')->legal_hold)->toBeFalse()
        ->and($file->fresh()->legal_hold)->toBeFalse();
});

it('refuses to set a legal hold without periods.manage, even with manage access on the directory', function () {
    // $this->user holds Manage on $this->mine -- plenty of directory_access
    // -- but the member role carries no periods.manage permission. Legal
    // hold is gated on that capability, not on directory reach (spec §9).
    $file = File::factory()->for($this->mine, 'directory')->create();

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->call('setLegalHold', true)
        ->assertForbidden();

    expect($file->fresh()->legal_hold)->toBeFalse();
});

// --- rename, move, trash a directory -----------------------------------------

it('renames a directory through the browser', function () {
    $child = Directory::factory()->for($this->mine, 'parent')->create(['name' => 'Old']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectDirectory', $child->id)
        ->set('renameValue', 'New')
        ->call('renameDirectory')
        ->assertHasNoErrors();

    expect($child->fresh()->name)->toBe('New');
});

it('refuses to rename a directory without edit access', function () {
    $viewer = viewOnlyMember($this->mine);
    $child = Directory::factory()->for($this->mine, 'parent')->create(['name' => 'Old']);

    Livewire::actingAs($viewer)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectDirectory', $child->id)
        ->set('renameValue', 'New')
        ->call('renameDirectory')
        ->assertForbidden();

    expect($child->fresh()->name)->toBe('Old');
});

it('moves a directory through the browser', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $child = Directory::factory()->for($this->mine, 'parent')->create();

    Livewire::actingAs($admin)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectDirectory', $child->id)
        ->set('moveDirectoryDestinationId', '')
        ->call('moveDirectory')
        ->assertHasNoErrors();

    expect($child->fresh()->parent_id)->toBeNull();
});

it('refuses to move a directory without directories.manage, manage access alone is not enough', function () {
    // $this->user holds Manage access on $this->mine, but the member role
    // carries no directories.manage permission.
    $child = Directory::factory()->for($this->mine, 'parent')->create();

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectDirectory', $child->id)
        ->call('moveDirectory')
        ->assertForbidden();

    expect($child->fresh()->parent_id)->toBe($this->mine->id);
});

/**
 * item/api-content (issue #23): moveDirectory()'s own half of the same fix
 * as moveFile() above -- see "404s moving a file to a destination the
 * viewer cannot even see" for the full reasoning. No existing test moved a
 * directory to a destination the mover could not even see (every prior
 * refusal here is a directories.manage gap with NO destination chosen at
 * all, which never reaches the destination lookup), so there was nothing to
 * flip for this half -- this is a NEW test, not a flipped one, written so
 * the query-level guard this item adds has something mutation-checking it.
 *
 * $mover deliberately holds directories.manage directly (bypassing role, the
 * same shape "offers an edit-reachable move destination..." uses for
 * per-user grants) rather than via the admin role, which also carries
 * directories.view-all -- that bypass would make EVERY directory viewable
 * to $mover unconditionally (DirectoryAccess::resolve()), and there would be
 * no destination left that could ever be outside their reach to prove this
 * guard with.
 */
it('404s moving a directory to a destination the mover cannot even see', function () {
    $mover = User::factory()->create();
    $mover->assignRole('member');
    $mover->givePermissionTo('directories.manage');

    DirectoryGrant::create([
        'directory_id' => $this->mine->id,
        'grantee_type' => 'user',
        'grantee_id' => $mover->id,
        'level' => AccessLevel::Manage,
    ]);

    $child = Directory::factory()->for($this->mine, 'parent')->create();

    Livewire::actingAs($mover)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectDirectory', $child->id)
        ->set('moveDirectoryDestinationId', (string) $this->theirs->id)
        ->call('moveDirectory')
        ->assertNotFound();

    expect($child->fresh()->parent_id)->toBe($this->mine->id);
});

it('trashes a directory through the browser', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $child = Directory::factory()->for($this->mine, 'parent')->create();

    Livewire::actingAs($admin)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectDirectory', $child->id)
        ->call('trashDirectory')
        ->assertSet('selectedDirectory', null);

    expect($child->fresh()->trashed())->toBeTrue();
});

it('refuses to trash a directory without directories.manage, manage access alone is not enough', function () {
    $child = Directory::factory()->for($this->mine, 'parent')->create();

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectDirectory', $child->id)
        ->call('trashDirectory')
        ->assertForbidden();

    expect($child->fresh()->trashed())->toBeFalse();
});

// --- controls stay hidden from a view-only viewer ----------------------------

it('hides rename, move and trash controls from a viewer holding view only', function () {
    $viewer = viewOnlyMember($this->mine);
    $file = File::factory()->for($this->mine, 'directory')->create();
    $child = Directory::factory()->for($this->mine, 'parent')->create();

    Livewire::actingAs($viewer)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->assertDontSee(__('Rename'))
        ->assertDontSeeHtml('data-test="trash-file-button"')
        ->assertDontSee(__('Set legal hold'))
        ->call('selectDirectory', $child->id)
        ->assertDontSee(__('Rename'))
        ->assertDontSeeHtml('data-test="trash-directory-button"');
});

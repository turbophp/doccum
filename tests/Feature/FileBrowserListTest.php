<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Livewire\Files\Browser;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * item/files-list-sort-select (issue #103): the files table's column sort
 * (name/owner/modified/size, whitelisted, falling back to name/asc on
 * anything unrecognised), row multi-select (plain click, ctrl-toggle,
 * shift-range computed in the CURRENT sort order) and bulk trash.
 *
 * "refuses a bulk trash for a viewer without edit access, and trashes
 * nothing" below is the expectFailing target for exactly one
 * .github/mutations.json entry, browser-bulk-trash-authorises-every-id: with
 * the matching authorize() call removed from Browser::bulkTrash(), nothing
 * in TrashFile checks anything on its own (CLAUDE.md is explicit that
 * Actions must not), so the call would go through unchecked and
 * assertForbidden() would fail. That is what makes this test the
 * load-bearing half of that mutation entry.
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

// --- sort --------------------------------------------------------------

it('sorts by name, ascending and descending', function () {
    File::factory()->for($this->mine, 'directory')->create(['name' => 'banana.txt']);
    File::factory()->for($this->mine, 'directory')->create(['name' => 'apple.txt']);
    File::factory()->for($this->mine, 'directory')->create(['name' => 'cherry.txt']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('sort', 'name')
        ->set('direction', 'asc')
        ->assertSeeInOrder(['apple.txt', 'banana.txt', 'cherry.txt']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('sort', 'name')
        ->set('direction', 'desc')
        ->assertSeeInOrder(['cherry.txt', 'banana.txt', 'apple.txt']);
});

it('sorts by size, ascending and descending', function () {
    File::factory()->for($this->mine, 'directory')->create(['name' => 'small.txt', 'size' => 100]);
    File::factory()->for($this->mine, 'directory')->create(['name' => 'medium.txt', 'size' => 5_000]);
    File::factory()->for($this->mine, 'directory')->create(['name' => 'large.txt', 'size' => 900_000]);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('sort', 'size')
        ->set('direction', 'asc')
        ->assertSeeInOrder(['small.txt', 'medium.txt', 'large.txt']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('sort', 'size')
        ->set('direction', 'desc')
        ->assertSeeInOrder(['large.txt', 'medium.txt', 'small.txt']);
});

it('sorts by modified, ascending and descending', function () {
    File::factory()->for($this->mine, 'directory')->create(['name' => 'oldest.txt', 'updated_at' => '2026-01-01 00:00:00']);
    File::factory()->for($this->mine, 'directory')->create(['name' => 'middle.txt', 'updated_at' => '2026-03-01 00:00:00']);
    File::factory()->for($this->mine, 'directory')->create(['name' => 'newest.txt', 'updated_at' => '2026-06-01 00:00:00']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('sort', 'modified')
        ->set('direction', 'asc')
        ->assertSeeInOrder(['oldest.txt', 'middle.txt', 'newest.txt']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('sort', 'modified')
        ->set('direction', 'desc')
        ->assertSeeInOrder(['newest.txt', 'middle.txt', 'oldest.txt']);
});

it('sorts by owner, ascending and descending', function () {
    // Names chosen to sort predictably and to appear nowhere else on the
    // page (the viewer, $this->user, is a third, unrelated user).
    $ann = User::factory()->create(['name' => 'Ann Ownersort']);
    $zoe = User::factory()->create(['name' => 'Zoe Ownersort']);

    File::factory()->for($this->mine, 'directory')->create(['name' => 'z-owned.txt', 'created_by' => $zoe->id]);
    File::factory()->for($this->mine, 'directory')->create(['name' => 'a-owned.txt', 'created_by' => $ann->id]);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('sort', 'owner')
        ->set('direction', 'asc')
        ->assertSeeInOrder(['Ann Ownersort', 'Zoe Ownersort']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('sort', 'owner')
        ->set('direction', 'desc')
        ->assertSeeInOrder(['Zoe Ownersort', 'Ann Ownersort']);
});

it("shows the file's own creator in the owner column, not the viewer", function () {
    // A creator DIFFERENT from the viewer ($this->user), with a factory
    // name distinctive enough that it cannot appear anywhere else in the
    // render by coincidence.
    $creator = User::factory()->create(['name' => 'Distinctive Creator Higgins']);
    File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt', 'created_by' => $creator->id]);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->assertSee('Distinctive Creator Higgins');
});

it('falls back to sorting by name when $sort is not a whitelisted column', function () {
    // An unwhitelisted column reaching orderBy() is SQL injection, not
    // merely a display bug (issue #103's own note) -- this string is
    // deliberately hostile, not merely unrecognised, and the assertion
    // that nothing throws IS part of what this test proves: a naive
    // "orderBy($this->sort)" would let SQLite choke on it, not silently
    // misbehave.
    File::factory()->for($this->mine, 'directory')->create(['name' => 'banana.txt']);
    File::factory()->for($this->mine, 'directory')->create(['name' => 'apple.txt']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('sort', 'id; DROP TABLE files; --')
        ->assertSeeInOrder(['apple.txt', 'banana.txt']);
});

it('falls back to ascending when $direction is neither asc nor desc', function () {
    File::factory()->for($this->mine, 'directory')->create(['name' => 'banana.txt']);
    File::factory()->for($this->mine, 'directory')->create(['name' => 'apple.txt']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('sort', 'name')
        ->set('direction', 'sideways')
        ->assertSeeInOrder(['apple.txt', 'banana.txt']);
});

it('sortBy() flips direction on a repeat click of the same column, and resets to ascending on a new one', function () {
    File::factory()->for($this->mine, 'directory')->create(['name' => 'banana.txt']);
    File::factory()->for($this->mine, 'directory')->create(['name' => 'apple.txt']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        // $sort/$direction default to 'name'/'asc', so the first click here
        // is on a DIFFERENT column from the default -- switching column,
        // not flipping direction, is what the assertion right after it proves.
        ->call('sortBy', 'size')
        ->assertSet('sort', 'size')
        ->assertSet('direction', 'asc')
        // A second click on the SAME column flips direction.
        ->call('sortBy', 'size')
        ->assertSet('direction', 'desc')
        // A click on yet another column switches again, resetting to
        // ascending regardless of size's direction just before this.
        ->call('sortBy', 'owner')
        ->assertSet('sort', 'owner')
        ->assertSet('direction', 'asc');
});

// --- multi-select --------------------------------------------------------

it('ctrl-click toggles a row, keeping the rest of the selection', function () {
    $a = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);
    $b = File::factory()->for($this->mine, 'directory')->create(['name' => 'b.txt']);
    $c = File::factory()->for($this->mine, 'directory')->create(['name' => 'c.txt']);

    $component = Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectRow', $a->id, false, false)
        ->call('selectRow', $c->id, false, true);

    expect($component->get('selectedIds'))->toEqualCanonicalizing([$a->id, $c->id]);

    // Ctrl-clicking A again removes it, keeping C.
    $component->call('selectRow', $a->id, false, true);
    expect($component->get('selectedIds'))->toBe([$c->id]);

    // A plain click (no modifier) replaces the whole selection.
    $component->call('selectRow', $b->id, false, false);
    expect($component->get('selectedIds'))->toBe([$b->id]);
});

it('shift-click selects the inclusive range in the CURRENT sort order, not id order', function () {
    // Created in this order (ascending id), deliberately NOT alphabetical.
    // Sorted by name DESCENDING, the on-screen order is delta, charlie,
    // bravo, alpha -- nothing like creation/id order (delta, alpha,
    // charlie, bravo) at all. A range computed from the two clicked ids'
    // own NUMERIC values (delta and alpha are the two smallest ids of the
    // four) would wrongly leave charlie and bravo out; computed over the
    // sorted listing, delta (row 1) to alpha (row 4) is every row.
    $delta = File::factory()->for($this->mine, 'directory')->create(['name' => 'delta.txt']);
    $alpha = File::factory()->for($this->mine, 'directory')->create(['name' => 'alpha.txt']);
    $charlie = File::factory()->for($this->mine, 'directory')->create(['name' => 'charlie.txt']);
    $bravo = File::factory()->for($this->mine, 'directory')->create(['name' => 'bravo.txt']);

    $component = Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('sort', 'name')
        ->set('direction', 'desc')
        ->call('selectRow', $delta->id, false, false) // anchor: first row on screen
        ->call('selectRow', $alpha->id, true, false); // shift: last row on screen

    expect($component->get('selectedIds'))->toEqualCanonicalizing([
        $delta->id, $charlie->id, $bravo->id, $alpha->id,
    ]);
});

it('a second shift-click extends the range from the ORIGINAL anchor, not the last shift target', function () {
    $a = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);
    $b = File::factory()->for($this->mine, 'directory')->create(['name' => 'b.txt']);
    $c = File::factory()->for($this->mine, 'directory')->create(['name' => 'c.txt']);
    $d = File::factory()->for($this->mine, 'directory')->create(['name' => 'd.txt']);

    // Sorted ascending by name (the default): a, b, c, d.
    $component = Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectRow', $a->id, false, false) // anchor at a
        ->call('selectRow', $b->id, true, false)   // shift -> {a, b}
        ->call('selectRow', $d->id, true, false);  // shift again, from a (not b) -> {a..d}

    expect($component->get('selectedIds'))->toEqualCanonicalizing([
        $a->id, $b->id, $c->id, $d->id,
    ]);
});

// --- bulk trash ------------------------------------------------------------

it('bulk-trashes exactly the selected files and leaves the rest', function () {
    $a = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);
    $b = File::factory()->for($this->mine, 'directory')->create(['name' => 'b.txt']);
    $c = File::factory()->for($this->mine, 'directory')->create(['name' => 'c.txt']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('selectedIds', [$a->id, $b->id])
        ->call('bulkTrash')
        ->assertSet('selectedIds', []);

    expect($a->fresh()->trashed())->toBeTrue()
        ->and($b->fresh()->trashed())->toBeTrue()
        ->and($c->fresh()->trashed())->toBeFalse();
});

/**
 * A member holding view alone on a directory -- enough to browse it and see
 * the table, not enough to trash anything in it.
 *
 * Declared here rather than reusing FileBrowserActionsTest's viewOnlyMember()
 * so this file does not depend on another test file having been loaded first.
 */
function listViewOnlyMember(Directory $directory): User
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

it('refuses a bulk trash for a viewer without edit access, and trashes nothing', function () {
    // The expectFailing target for browser-bulk-trash-authorises-every-id,
    // and isolated on purpose: both files sit in the directory being
    // browsed, so bulkTrash()'s own resolution keeps both and its
    // exact-resolution abort_if() cannot fire. TrashFile authorises nothing
    // (CLAUDE.md: actions never authorise). The authorize pass is therefore
    // the only thing that can refuse, and with it deleted this viewer
    // trashes both files.
    //
    // This asserts the all-refused case rather than the item's literal
    // "any ONE of several refused", because that case cannot be built:
    // FilePolicy::delete() is a global permission plus directory access and
    // has no per-file component, so every file in one directory answers
    // identically for one user. See bulkTrash()'s docblock. Constructing a
    // one-of-several refusal would mean selecting a file from ANOTHER
    // directory, which this component now resolves away before authorize()
    // ever runs -- a test that would prove the abort_if(), not this guard.
    $viewer = listViewOnlyMember($this->mine);

    $fileA = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);
    $fileB = File::factory()->for($this->mine, 'directory')->create(['name' => 'b.txt']);

    Livewire::actingAs($viewer)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('selectedIds', [$fileA->id, $fileB->id])
        ->call('bulkTrash')
        ->assertForbidden();

    expect($fileA->fresh()->trashed())->toBeFalse()
        ->and($fileB->fresh()->trashed())->toBeFalse();
});

it('trashes nothing when a selected id is not in the directory being browsed', function () {
    // $selectedIds comes from the client, so an id from outside the listing
    // did not come from the UI. It is refused wholesale rather than quietly
    // dropped: trashing the rest of the selection and reporting success
    // would be trashing something other than what was selected.
    $mine = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);
    $theirs = File::factory()->for($this->theirs, 'directory')->create(['name' => 'b.txt']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('selectedIds', [$mine->id, $theirs->id])
        ->call('bulkTrash')
        ->assertNotFound();

    expect($mine->fresh()->trashed())->toBeFalse()
        ->and($theirs->fresh()->trashed())->toBeFalse();
});

it('trashes nothing when a selected id no longer exists', function () {
    $mine = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('selectedIds', [$mine->id, $mine->id + 9999])
        ->call('bulkTrash')
        ->assertNotFound();

    expect($mine->fresh()->trashed())->toBeFalse();
});

// --- folders in the selection ----------------------------------------------
//
// Folders tick alongside files and go into the same bulk trash. What matters
// is that they inherit the same all-or-nothing rule: a refusal on a folder
// must not leave the files already trashed, and an id that resolves to
// nothing must stop the whole operation rather than be quietly dropped.

it('trashes selected folders alongside selected files', function () {
    // An admin, because DirectoryPolicy::delete asks for directories.manage
    // and a member does not hold it -- see the refusal test below, which is
    // the same scenario from the other side.
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    grant($this->mine, $admin, AccessLevel::Manage);

    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);
    $folder = Directory::factory()->for($this->mine, 'parent')->create(['name' => 'Sub']);

    Livewire::actingAs($admin)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('selectedIds', [$file->id])
        ->set('selectedDirectoryIds', [$folder->id])
        ->call('bulkTrash')
        ->assertOk();

    expect($file->fresh()->trashed())->toBeTrue()
        ->and($folder->fresh()->trashed())->toBeTrue();
});

it('trashes nothing at all when the viewer may delete the file but not the folder', function () {
    // The member holds Manage on the directory through its grant, so
    // FilePolicy::delete passes for the file -- but DirectoryPolicy::delete
    // also requires the global directories.manage permission, which the
    // member role does not carry. This is the two-layer rule doing its job,
    // and the point of the test is the FILE: it is authorised first and would
    // already be in the trash if the folder were authorised after the fact
    // rather than before anything is trashed.
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);
    $folder = Directory::factory()->for($this->mine, 'parent')->create(['name' => 'Sub']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('selectedIds', [$file->id])
        ->set('selectedDirectoryIds', [$folder->id])
        ->call('bulkTrash')
        ->assertForbidden();

    expect($file->fresh()->trashed())->toBeFalse()
        ->and($folder->fresh()->trashed())->toBeFalse();
});

it('trashes nothing at all when a selected folder is not a child of the browsed directory', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    grant($this->mine, $admin, AccessLevel::Manage);

    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);
    $elsewhere = Directory::factory()->create(['name' => 'Elsewhere']);

    Livewire::actingAs($admin)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('selectedIds', [$file->id])
        ->set('selectedDirectoryIds', [$elsewhere->id])
        ->call('bulkTrash')
        ->assertNotFound();

    expect($file->fresh()->trashed())->toBeFalse()
        ->and($elsewhere->fresh()->trashed())->toBeFalse();
});

it('toggles a folder in and out of the selection', function () {
    $folder = Directory::factory()->for($this->mine, 'parent')->create(['name' => 'Sub']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectDirectoryRow', $folder->id)
        ->assertSet('selectedDirectoryIds', [$folder->id])
        ->call('selectDirectoryRow', $folder->id)
        ->assertSet('selectedDirectoryIds', []);
});

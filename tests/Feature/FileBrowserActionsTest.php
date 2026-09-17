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
 * item/files-actions-ui (issue #101): selecting a file or directory row opens
 * the detail panel's Rename, Move, Trash and legal-hold controls, and every
 * one of them is authorised in THIS component through a Policy -- never
 * inside the Action it calls (CLAUDE.md).
 *
 * Every "refuses ..." test below is the expectFailing target for exactly one
 * .github/mutations.json entry: with the matching `$this->authorize(...)`
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
    // $this->user holds Manage on $this->mine only, nothing on $this->theirs
    // -- and mount() lets a null $directory through with no check at all
    // (browsing the root), so selectDirectory()'s own authorize('view', ...)
    // call is the only thing standing between a directory id typed straight
    // into the wire:click and one the viewer has no grant on whatsoever.
    Livewire::actingAs($this->user)
        ->test(Browser::class)
        ->call('selectDirectory', $this->theirs->id)
        ->assertForbidden();
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

it('refuses to move a file without edit access on both ends', function () {
    $viewer = viewOnlyMember($this->mine);
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'a.txt']);

    Livewire::actingAs($viewer)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->set('moveFileDestinationId', $this->theirs->id)
        ->call('moveFile')
        ->assertForbidden();

    expect($file->fresh()->directory_id)->toBe($this->mine->id);
});

it('lists only edit-reachable directories as move destinations, filtered in the query', function () {
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

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->call('selectFile', $file->id)
        ->assertSee('Editable')
        ->assertDontSee('ViewOnlyDestination');
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

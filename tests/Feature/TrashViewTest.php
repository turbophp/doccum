<?php

declare(strict_types=1);

use App\Actions\Directories\TrashDirectory;
use App\Actions\Files\TrashFile;
use App\Enums\AccessLevel;
use App\Livewire\Trash\Index as Trash;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * item/trash-view (issue #15): a Trash page that lists what the viewer may
 * see of the trash, and nothing else, with Restore and Purge wired to the
 * existing RestoreFile, PurgeFile and RestoreDirectory actions.
 *
 * The doneWhen is explicit that "another user's trashed items never appear"
 * is asserted AT QUERY LEVEL. The two tests under "--- isolation ---" below
 * assert that directly against App\Livewire\Trash\Index::render()'s own
 * $files/$directories view data -- not merely against what the rendered
 * HTML happens to contain -- and only then also check the HTML, the same
 * belt-and-suspenders shape CLAUDE.md asks container-smoke checks for
 * ("database evidence first, DOM second"), adapted to a component test
 * where the "database" is the query result itself.
 *
 * Every "refuses ..." test is the expectFailing target for exactly one
 * .github/mutations.json entry. With the matching `$this->authorize(...)`
 * call removed from app/Livewire/Trash/Index.php, none of RestoreFile,
 * PurgeFile or RestoreDirectory checks anything on its own (CLAUDE.md:
 * actions never authorise), so the call would go through unchecked and
 * assertForbidden() would fail.
 */
function grantTrashAccess(Directory $directory, User $user, AccessLevel $level): void
{
    DirectoryGrant::create([
        'directory_id' => $directory->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $level,
    ]);
}

beforeEach(function () {
    Storage::fake('documents');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->mine = Directory::factory()->create(['name' => 'Mine']);
    $this->theirs = Directory::factory()->create(['name' => 'Theirs']);

    $this->user = User::factory()->create();
    $this->user->assignRole('member');
    grantTrashAccess($this->mine, $this->user, AccessLevel::Manage);

    $this->stranger = User::factory()->create();
    $this->stranger->assignRole('member');
    grantTrashAccess($this->theirs, $this->stranger, AccessLevel::Manage);
});

// --- isolation (issue #15's own doneWhen) -----------------------------------

it('never lists another user\'s trashed file, asserted against the query result itself', function () {
    $mine = File::factory()->for($this->mine, 'directory')->create(['name' => 'mine.txt']);
    $theirs = File::factory()->for($this->theirs, 'directory')->create(['name' => 'theirs.txt']);

    app(TrashFile::class)->handle($mine);
    app(TrashFile::class)->handle($theirs);

    $component = Livewire::actingAs($this->user)->test(Trash::class);

    // The QUERY-level assertion the doneWhen names: render()'s own $files
    // never contains $theirs, regardless of what the view does with it.
    expect($component->viewData('files')->pluck('id')->all())
        ->toBe([$mine->id]);

    $component->assertSee('mine.txt')->assertDontSee('theirs.txt');
});

it('never lists another user\'s trashed directory, asserted against the query result itself', function () {
    $mineChild = Directory::factory()->for($this->mine, 'parent')->create(['name' => 'MineChild']);
    $theirsChild = Directory::factory()->for($this->theirs, 'parent')->create(['name' => 'TheirsChild']);

    app(TrashDirectory::class)->handle($mineChild->fresh());
    app(TrashDirectory::class)->handle($theirsChild->fresh());

    $component = Livewire::actingAs($this->user)->test(Trash::class);

    expect($component->viewData('directories')->pluck('id')->all())
        ->toBe([$mineChild->id]);

    $component->assertSee('MineChild')->assertDontSee('TheirsChild');
});

it('lists a file trashed independently of its live directory', function () {
    // The ordinary case: TrashFile alone, directory untouched. Proves the
    // "live half" of render()'s reachable-directory union, not only the
    // cascade half the two tests above exercise.
    $file = File::factory()->for($this->mine, 'directory')->create(['name' => 'solo.txt']);
    app(TrashFile::class)->handle($file);

    $component = Livewire::actingAs($this->user)->test(Trash::class);

    expect($component->viewData('files')->pluck('id')->all())->toBe([$file->id]);
});

// --- restore a file ----------------------------------------------------------

it('restores a trashed file through the Trash page', function () {
    $file = File::factory()->for($this->mine, 'directory')->create();
    app(TrashFile::class)->handle($file);

    Livewire::actingAs($this->user)
        ->test(Trash::class)
        ->call('restoreFile', $file->id);

    expect($file->fresh()->trashed())->toBeFalse();
});

it('refuses to restore a file without edit access', function () {
    $file = File::factory()->for($this->mine, 'directory')->create();
    app(TrashFile::class)->handle($file);

    $viewer = User::factory()->create();
    $viewer->assignRole('member');
    grantTrashAccess($this->mine, $viewer, AccessLevel::View);

    Livewire::actingAs($viewer)
        ->test(Trash::class)
        ->call('restoreFile', $file->id)
        ->assertForbidden();

    expect(File::withTrashed()->findOrFail($file->id)->trashed())->toBeTrue();
});

it('404s restoreFile for an id that is not trashed', function () {
    // find(), not findOrFail() -- CLAUDE.md: a Livewire component test never
    // sees ModelNotFoundException rendered as a 404, so this proves
    // Index::restoreFile() uses the abort_if() shape instead. Asserting the
    // SPECIFIC refusal matters: a live file's own directory access would
    // otherwise let a 403 pass this test just as well as a 404, proving
    // nothing about which refusal actually fired.
    $file = File::factory()->for($this->mine, 'directory')->create();

    Livewire::actingAs($this->user)
        ->test(Trash::class)
        ->call('restoreFile', $file->id)
        ->assertNotFound();
});

// --- purge a file -------------------------------------------------------------

it('purges a trashed file through the Trash page, permanently deleting it', function () {
    $file = File::factory()->for($this->mine, 'directory')->create();
    app(TrashFile::class)->handle($file);

    Livewire::actingAs($this->user)
        ->test(Trash::class)
        ->call('purgeFile', $file->id);

    expect(File::withTrashed()->find($file->id))->toBeNull();
});

it('refuses to purge a file without manage access, edit alone is not enough', function () {
    $file = File::factory()->for($this->mine, 'directory')->create();
    app(TrashFile::class)->handle($file);

    $editor = User::factory()->create();
    $editor->assignRole('member');
    grantTrashAccess($this->mine, $editor, AccessLevel::Edit);

    Livewire::actingAs($editor)
        ->test(Trash::class)
        ->call('purgeFile', $file->id)
        ->assertForbidden();

    expect(File::withTrashed()->find($file->id))->not->toBeNull();
});

it('404s purgeFile for an id that is not trashed', function () {
    $file = File::factory()->for($this->mine, 'directory')->create();

    Livewire::actingAs($this->user)
        ->test(Trash::class)
        ->call('purgeFile', $file->id)
        ->assertNotFound();
});

// --- restore a directory ------------------------------------------------------

it('restores a trashed directory, and its cascade, through the Trash page', function () {
    $child = Directory::factory()->for($this->mine, 'parent')->create();
    $childFile = File::factory()->for($child, 'directory')->create();
    app(TrashDirectory::class)->handle($child->fresh());

    $this->user->assignRole('admin');

    Livewire::actingAs($this->user)
        ->test(Trash::class)
        ->call('restoreDirectory', $child->id);

    expect($child->fresh()->trashed())->toBeFalse()
        ->and($childFile->fresh()->trashed())->toBeFalse();
});

it('refuses to restore a directory without directories.manage, manage access alone is not enough', function () {
    // $this->user holds Manage directory_access on $this->mine via the
    // member role, but member carries no directories.manage permission
    // (RolesAndPermissionsSeeder::MEMBER_PERMISSIONS) -- the exact gap
    // DirectoryPolicy::restore() closes.
    $child = Directory::factory()->for($this->mine, 'parent')->create();
    app(TrashDirectory::class)->handle($child->fresh());

    Livewire::actingAs($this->user)
        ->test(Trash::class)
        ->call('restoreDirectory', $child->id)
        ->assertForbidden();

    expect(Directory::withTrashed()->findOrFail($child->id)->trashed())->toBeTrue();
});

it('404s restoreDirectory for an id that is not trashed', function () {
    $child = Directory::factory()->for($this->mine, 'parent')->create();

    $this->user->assignRole('admin');

    Livewire::actingAs($this->user)
        ->test(Trash::class)
        ->call('restoreDirectory', $child->id)
        ->assertNotFound();
});

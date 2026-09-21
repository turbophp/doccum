<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Livewire\Files\Browser;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use App\Services\DirectoryAccess;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// The row's icon actions -- View, Compress, Download, Trash -- each reach a
// component method, and every one of those re-resolves and re-authorises its
// subject. The icons are an affordance; none of them is the guard.

beforeEach(function () {
    Storage::fake('documents');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->member = User::factory()->create();
    $this->member->assignRole('member');

    $this->dir = Directory::factory()->create(['name' => 'Cases']);
    grant($this->dir, $this->member, AccessLevel::Manage);
});

it('opens a preview for a file the viewer may see', function () {
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.pdf', 'mime' => 'application/pdf']);

    Livewire::actingAs($this->member)
        ->test(Browser::class, ['directory' => $this->dir])
        ->call('preview', $file->id)
        ->assertOk()
        ->assertSet('previewFileId', $file->id);
});

it('refuses to preview a file the viewer cannot reach', function () {
    $elsewhere = Directory::factory()->create(['name' => 'Theirs']);
    $file = File::factory()->for($elsewhere, 'directory')->create(['name' => 'secret.pdf']);

    Livewire::actingAs($this->member)
        ->test(Browser::class, ['directory' => $this->dir])
        ->call('preview', $file->id)
        ->assertNotFound();
});

it('answers a previewed file id that does not exist the same way as one out of reach', function () {
    $nonexistent = ((int) File::query()->max('id')) + 1;

    Livewire::actingAs($this->member)
        ->test(Browser::class, ['directory' => $this->dir])
        ->call('preview', $nonexistent)
        ->assertNotFound();
});

it('stops showing a preview once access is revoked, without being closed', function () {
    // The dialog is re-authorised on every render rather than trusting the id
    // it was opened with. Without that, a preview opened legitimately keeps
    // serving after the grant behind it is gone.
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.pdf']);

    $component = Livewire::actingAs($this->member)
        ->test(Browser::class, ['directory' => $this->dir])
        ->call('preview', $file->id);

    expect($component->instance()->previewFile())->not->toBeNull();

    // Deleted through the models rather than with a mass delete: CLAUDE.md
    // records that Model::query()->delete() fires no events, so
    // DirectoryAccess's memo would still answer from the grant that is gone.
    DirectoryGrant::query()
        ->where('grantee_type', 'user')
        ->where('grantee_id', $this->member->id)
        ->get()
        ->each
        ->delete();

    app(DirectoryAccess::class)->flush();

    expect($component->instance()->previewFile())->toBeNull();
});

it('trashes a file from its row', function () {
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt']);

    Livewire::actingAs($this->member)
        ->test(Browser::class, ['directory' => $this->dir])
        ->call('trashFileRow', $file->id)
        ->assertOk();

    expect($file->fresh()->trashed())->toBeTrue();
});

/**
 * Renamed under item/reach-existence-oracle clause 2, because the old name
 * described a case this test never set up. It was "refuses to trash a file
 * from a row when the viewer may not delete it", which reads as a permission
 * refusal -- but $elsewhere carries no grant for $this->member at all, so the
 * file is out of REACH, not merely undeletable. The distinction did not
 * matter while both answered 403. It matters now: reach answers 404 and an
 * insufficient level answers 403, and a test whose name says one while its
 * fixture builds the other is how the next reader picks the wrong witness.
 */
it('refuses to trash a file from a row when the viewer cannot reach it', function () {
    $elsewhere = Directory::factory()->create(['name' => 'Theirs']);
    $file = File::factory()->for($elsewhere, 'directory')->create(['name' => 'theirs.txt']);

    Livewire::actingAs($this->member)
        ->test(Browser::class, ['directory' => $this->dir])
        ->call('trashFileRow', $file->id)
        ->assertNotFound();

    expect($file->fresh()->trashed())->toBeFalse();
});

it('answers a trashed-row file id that does not exist the same way as one out of reach', function () {
    $nonexistent = ((int) File::query()->max('id')) + 1;

    Livewire::actingAs($this->member)
        ->test(Browser::class, ['directory' => $this->dir])
        ->call('trashFileRow', $nonexistent)
        ->assertNotFound();
});

it('trashes a folder from its row', function () {
    $folder = Directory::factory()->for($this->dir, 'parent')->create(['name' => 'Sub']);
    grant($this->dir, $this->admin, AccessLevel::Manage);

    Livewire::actingAs($this->admin)
        ->test(Browser::class, ['directory' => $this->dir])
        ->call('trashDirectoryRow', $folder->id)
        ->assertOk();

    expect($folder->fresh()->trashed())->toBeTrue();
});

it('refuses to trash a folder that is not a child of the directory being browsed', function () {
    $elsewhere = Directory::factory()->create(['name' => 'Elsewhere']);

    Livewire::actingAs($this->admin)
        ->test(Browser::class, ['directory' => $this->dir])
        ->call('trashDirectoryRow', $elsewhere->id)
        ->assertNotFound();

    expect($elsewhere->fresh()->trashed())->toBeFalse();
});

// --- preview navigation ------------------------------------------------------
//
// Stepping moves through the listing the person is looking at, in the order
// they see, and every step is authorised exactly like opening one is.

it('steps to the next and previous file in the order the listing shows', function () {
    $a = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt']);
    $b = File::factory()->for($this->dir, 'directory')->create(['name' => 'b.txt']);
    $c = File::factory()->for($this->dir, 'directory')->create(['name' => 'c.txt']);

    $component = Livewire::actingAs($this->member)
        ->test(Browser::class, ['directory' => $this->dir])
        ->call('preview', $b->id);

    $component->call('previewStep', 1)->assertSet('previewFileId', $c->id);
    $component->call('previewStep', -1)->assertSet('previewFileId', $b->id);
    $component->call('previewStep', -1)->assertSet('previewFileId', $a->id);
});

it('stops at the ends rather than wrapping around', function () {
    $a = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt']);
    File::factory()->for($this->dir, 'directory')->create(['name' => 'b.txt']);

    Livewire::actingAs($this->member)
        ->test(Browser::class, ['directory' => $this->dir])
        ->call('preview', $a->id)
        ->call('previewStep', -1)
        ->assertSet('previewFileId', $a->id);
});

it('follows the listing order rather than the id order when sorted', function () {
    // Created in one order, sorted into another: stepping must agree with the
    // rows on screen, not with whichever integers the database handed out.
    $first = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt', 'size' => 900]);
    $second = File::factory()->for($this->dir, 'directory')->create(['name' => 'b.txt', 'size' => 100]);

    Livewire::actingAs($this->member)
        ->test(Browser::class, ['directory' => $this->dir])
        ->set('sort', 'size')
        ->set('direction', 'asc')
        ->call('preview', $second->id)
        ->call('previewStep', 1)
        ->assertSet('previewFileId', $first->id);
});

it('reports the position in the listing, one-based', function () {
    File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt']);
    $b = File::factory()->for($this->dir, 'directory')->create(['name' => 'b.txt']);
    File::factory()->for($this->dir, 'directory')->create(['name' => 'c.txt']);

    $component = Livewire::actingAs($this->member)
        ->test(Browser::class, ['directory' => $this->dir])
        ->call('preview', $b->id);

    expect($component->instance()->previewPosition())->toBe([2, 3]);
});

it('refuses to step into a file the viewer cannot reach', function () {
    // The neighbouring row is not evidence: previewStep() goes through
    // preview(), which authorises, so a listing that somehow contained an
    // unreachable id still cannot open it.
    $mine = File::factory()->for($this->dir, 'directory')->create(['name' => 'mine.txt']);
    $elsewhere = Directory::factory()->create(['name' => 'Theirs']);
    $theirs = File::factory()->for($elsewhere, 'directory')->create(['name' => 'theirs.txt']);

    Livewire::actingAs($this->member)
        ->test(Browser::class, ['directory' => $this->dir])
        ->call('preview', $mine->id)
        ->call('preview', $theirs->id)
        ->assertNotFound();
});

// --- a preview is a place, not a dialog -------------------------------------

it('opens the file its link names, in that file\'s own directory', function () {
    // The point of putting the preview in the URL is that the link can be
    // sent to someone. A recipient has the file, not the folder, so the page
    // has to find the folder itself -- otherwise a shared link lands in the
    // root with a dialog over the wrong listing.
    $elsewhere = Directory::factory()->create(['name' => 'Filed']);
    grant($elsewhere, $this->member, AccessLevel::Manage);
    $file = File::factory()->for($elsewhere, 'directory')->create(['name' => 'shared.txt']);

    $component = Livewire::actingAs($this->member)
        ->withQueryParams(['file' => $file->id])
        ->test(Browser::class);

    $component->assertSet('previewFileId', $file->id);

    expect($component->instance()->previewFile()?->getKey())->toBe($file->getKey());
});

it('drops a file parameter the viewer may not see, rather than refusing the page', function () {
    // The link is simply a link to the file browser for someone without the
    // grant. Refusing the whole page would tell them a file exists at that id,
    // which is more than they are entitled to know.
    $elsewhere = Directory::factory()->create(['name' => 'Theirs']);
    $file = File::factory()->for($elsewhere, 'directory')->create(['name' => 'secret.txt']);

    Livewire::actingAs($this->member)
        ->withQueryParams(['file' => $file->id])
        ->test(Browser::class)
        ->assertOk()
        ->assertSet('previewFileId', null);
});

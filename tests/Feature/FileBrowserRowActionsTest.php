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
        ->assertForbidden();
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

it('refuses to trash a file from a row when the viewer may not delete it', function () {
    $elsewhere = Directory::factory()->create(['name' => 'Theirs']);
    $file = File::factory()->for($elsewhere, 'directory')->create(['name' => 'theirs.txt']);

    Livewire::actingAs($this->member)
        ->test(Browser::class, ['directory' => $this->dir])
        ->call('trashFileRow', $file->id)
        ->assertForbidden();

    expect($file->fresh()->trashed())->toBeFalse();
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

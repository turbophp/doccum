<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Livewire\Files\UploadTray;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// Drag, drop and the upload tray (implementation plan Task 7). Two things
// land in this one component: moving an existing row (dropped on a folder
// row or a tree node) and uploading a file dragged in from the desktop.
// resources/js/shell/dnd.js decides what the pointer looks like; it never
// authorises anything, so what matters here is that the SAME refusal a drag
// would show up front is what the server enforces when the drag is skipped
// entirely and the request is posted directly (CLAUDE.md, "Actions never
// authorise. Callers do.", and "A drag handler is not an authorisation
// boundary").

function grantOn(Directory $dir, User $user, AccessLevel $level): void
{
    DirectoryGrant::create([
        'directory_id' => $dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $level,
    ]);
}

beforeEach(function () {
    Storage::fake('documents');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('member');

    $this->source = Directory::factory()->create(['name' => 'Contracts']);
    $this->target = Directory::factory()->create(['name' => 'Archive']);

    grantOn($this->source, $this->user, AccessLevel::Edit);
    grantOn($this->target, $this->user, AccessLevel::Edit);
});

// --- Moving a file dropped on a folder row or a tree node -----------------

it('moves a file onto a folder the viewer has edit access to', function () {
    $file = File::factory()->for($this->source, 'directory')->create(['name' => 'a.txt']);

    Livewire::actingAs($this->user)
        ->test(UploadTray::class, ['directory' => $this->source])
        ->call('moveFile', $file->id, $this->target->id)
        ->assertDispatched('drop-settled');

    expect($file->fresh()->directory_id)->toBe($this->target->id);
});

it('refuses to move a file onto a directory the viewer has no edit access to, and leaves it where it was', function () {
    $stranger = User::factory()->create();
    $stranger->assignRole('member');
    grantOn($this->source, $stranger, AccessLevel::Edit);
    // No grant at all on $this->target for $stranger.

    $file = File::factory()->for($this->source, 'directory')->create(['name' => 'a.txt']);

    Livewire::actingAs($stranger)
        ->test(UploadTray::class, ['directory' => $this->source])
        ->call('moveFile', $file->id, $this->target->id)
        ->assertForbidden();

    expect($file->fresh()->directory_id)->toBe($this->source->id);
});

it('refuses to move a file whose period is archived, and states the reason instead of doing nothing', function () {
    $file = File::factory()->for($this->source, 'directory')->create([
        'name' => 'a.txt',
        'period_year' => 2024,
        'period_month' => 3,
    ]);
    ArchivePeriod::factory()->create(['year' => 2024, 'month' => 3, 'archived_at' => now()]);

    Livewire::actingAs($this->user)
        ->test(UploadTray::class, ['directory' => $this->source])
        ->call('moveFile', $file->id, $this->target->id)
        ->assertDispatched('drop-refused');

    expect($file->fresh()->directory_id)->toBe($this->source->id);
});

it('refuses to move a file onto a directory that already has a file of that name, and states why', function () {
    File::factory()->for($this->target, 'directory')->create(['name' => 'a.txt']);
    $file = File::factory()->for($this->source, 'directory')->create(['name' => 'a.txt']);

    Livewire::actingAs($this->user)
        ->test(UploadTray::class, ['directory' => $this->source])
        ->call('moveFile', $file->id, $this->target->id)
        ->assertDispatched('drop-refused');

    expect($file->fresh()->directory_id)->toBe($this->source->id);
});

it('moves a directory the viewer manages onto a directory it may edit into', function () {
    $this->user->assignRole('admin');
    $moved = Directory::factory()->for($this->source, 'parent')->create(['name' => 'Old contracts']);
    grantOn($moved, $this->user, AccessLevel::Manage);

    Livewire::actingAs($this->user)
        ->test(UploadTray::class, ['directory' => $this->source])
        ->call('moveDirectory', $moved->id, $this->target->id)
        ->assertDispatched('drop-settled');

    expect($moved->fresh()->parent_id)->toBe($this->target->id);
});

it('refuses to move a directory without the directories.manage capability, even with access to both ends', function () {
    // $this->user only carries the member role's permissions, which do not
    // include directories.manage (see RolesAndPermissionsSeeder).
    $moved = Directory::factory()->for($this->source, 'parent')->create(['name' => 'Old contracts']);
    grantOn($moved, $this->user, AccessLevel::Manage);

    Livewire::actingAs($this->user)
        ->test(UploadTray::class, ['directory' => $this->source])
        ->call('moveDirectory', $moved->id, $this->target->id)
        ->assertForbidden();

    expect($moved->fresh()->parent_id)->toBe($this->source->id);
});

it('routes the shell-drop event dnd.js dispatches to the matching move method', function () {
    $file = File::factory()->for($this->source, 'directory')->create(['name' => 'a.txt']);

    Livewire::actingAs($this->user)
        ->test(UploadTray::class, ['directory' => $this->source])
        ->call('handleDrop', 'file', $file->id, $this->target->id)
        ->assertDispatched('drop-settled');

    expect($file->fresh()->directory_id)->toBe($this->target->id);
});

// --- Files dragged in from the desktop -------------------------------------

it('uploads a file dropped from the desktop into the pane directory', function () {
    Livewire::actingAs($this->user)
        ->test(UploadTray::class, ['directory' => $this->source])
        ->set('incoming', [UploadedFile::fake()->create('Report.pdf', 12, 'application/pdf')])
        ->assertHasNoErrors();

    $file = File::where('name', 'Report.pdf')->firstOrFail();

    expect($file->directory_id)->toBe($this->source->id)
        ->and(Storage::disk('documents')->exists($file->currentVersion->object_key))->toBeTrue();
});

it('uploads a file dropped onto a specific folder row rather than the pane directory', function () {
    Livewire::actingAs($this->user)
        ->test(UploadTray::class, ['directory' => $this->source])
        ->set('uploadTargetDirectoryId', $this->target->id)
        ->set('incoming', [UploadedFile::fake()->create('Report.pdf', 12, 'application/pdf')])
        ->assertHasNoErrors();

    $file = File::where('name', 'Report.pdf')->firstOrFail();

    expect($file->directory_id)->toBe($this->target->id);
});

it('records a failed upload in the tray, rather than creating nothing silently, when the viewer lacks upload access', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('member');
    grantOn($this->source, $viewer, AccessLevel::View);

    $component = Livewire::actingAs($viewer)
        ->test(UploadTray::class, ['directory' => $this->source])
        ->set('incoming', [UploadedFile::fake()->create('Report.pdf', 12, 'application/pdf')]);

    expect(File::count())->toBe(0)
        ->and($component->get('items'))->toHaveCount(1)
        ->and($component->get('items')[0]['status'])->toBe('failed')
        ->and($component->get('items')[0]['reason'])->toBe('No upload access here');
});

it('records a failed upload in the tray, with the reason, when the current period is archived', function () {
    ArchivePeriod::factory()->create([
        'year' => (int) now()->year,
        'month' => (int) now()->month,
        'archived_at' => now(),
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(UploadTray::class, ['directory' => $this->source])
        ->set('incoming', [UploadedFile::fake()->create('Report.pdf', 12, 'application/pdf')]);

    expect(File::count())->toBe(0)
        ->and($component->get('items')[0]['status'])->toBe('failed')
        ->and($component->get('items')[0]['reason'])->not->toBeNull();
});

it('records a done upload in the tray on success', function () {
    $component = Livewire::actingAs($this->user)
        ->test(UploadTray::class, ['directory' => $this->source])
        ->set('incoming', [UploadedFile::fake()->create('Report.pdf', 12, 'application/pdf')]);

    expect($component->get('items'))->toHaveCount(1)
        ->and($component->get('items')[0]['status'])->toBe('done')
        ->and($component->get('items')[0]['reason'])->toBeNull();
});

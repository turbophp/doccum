<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Livewire\Files\Browser;
use App\Models\Directory;
use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// Dragging is a gesture, not a second set of rules: dropMove() re-resolves and
// re-authorises both ends through the same policies the Move selects use. A
// drop arrives as three integers from the client, and the DOM they came from
// proves nothing about what the person may do.

beforeEach(function () {
    Storage::fake('documents');
    $this->seed(RolesAndPermissionsSeeder::class);

    // Two actors, because the two layers answer differently. The admin holds
    // directories.view-all, which resolves Manage on EVERY directory -- so it
    // can never demonstrate a refusal for being out of reach. The member holds
    // grants on these two directories and nothing else, which is what makes
    // "cannot reach" a real state rather than a hypothetical one.
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->user = User::factory()->create();
    $this->user->assignRole('member');

    $this->source = Directory::factory()->create(['name' => 'Inbox']);
    $this->target = Directory::factory()->create(['name' => 'Filed']);

    grant($this->source, $this->user, AccessLevel::Manage);
    grant($this->target, $this->user, AccessLevel::Manage);
});

it('moves a dropped file into the directory it was dropped on', function () {
    $file = File::factory()->for($this->source, 'directory')->create(['name' => 'a.txt']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->source])
        ->call('dropMove', 'file', $file->id, $this->target->id)
        ->assertOk();

    expect($file->fresh()->directory_id)->toBe($this->target->id);
});

it('moves a dropped folder into the directory it was dropped on', function () {
    // An admin: DirectoryPolicy::move asks for the global directories.manage
    // on top of access, and a member does not hold it.
    $folder = Directory::factory()->for($this->source, 'parent')->create(['name' => 'Sub']);

    Livewire::actingAs($this->admin)
        ->test(Browser::class, ['directory' => $this->source])
        ->call('dropMove', 'directory', $folder->id, $this->target->id)
        ->assertOk();

    expect($folder->fresh()->parent_id)->toBe($this->target->id);
});

/**
 * The two halves of one fact, and neither means anything alone.
 *
 * A destination the viewer cannot reach and a destination that does not
 * exist must answer IDENTICALLY, or the status code tells the caller which
 * directory ids exist -- an existence oracle (issue #109,
 * item/reach-existence-oracle). Asserting only the first half would pass
 * just as well against the old findOrFail()-then-authorize() shape's 403,
 * which is the leak; asserting only the second would pass against any
 * implementation at all. The pair is the assertion.
 */
it('refuses a drop into a directory the viewer cannot reach, and moves nothing', function () {
    $file = File::factory()->for($this->source, 'directory')->create(['name' => 'a.txt']);
    $elsewhere = Directory::factory()->create(['name' => 'Not mine']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->source])
        ->call('dropMove', 'file', $file->id, $elsewhere->id)
        ->assertNotFound();

    expect($file->fresh()->directory_id)->toBe($this->source->id);
});

it('answers a destination that does not exist the same way as one out of reach', function () {
    $file = File::factory()->for($this->source, 'directory')->create(['name' => 'a.txt']);

    // Deliberately an id no row carries: max()+1 rather than a literal, so
    // the test cannot start passing for the wrong reason if seeding grows.
    $nonexistent = ((int) Directory::query()->max('id')) + 1;

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->source])
        ->call('dropMove', 'file', $file->id, $nonexistent)
        ->assertNotFound();

    expect($file->fresh()->directory_id)->toBe($this->source->id);
});

/**
 * The witness for dropMove()'s authorize() on the FILE branch.
 *
 * It has to be a destination the viewer CAN reach, because clause 1 of
 * item/reach-existence-oracle scoped the destination lookup and that scoping
 * aborts 404 before authorize() is ever reached for anything out of reach.
 * View level is inside viewableDirectoryIds() -- so the lookup finds it and
 * the 404 does not fire -- while FilePolicy::move() requires Edit on the
 * destination, so the refusal comes from the policy. Removing the authorize
 * lets this drop through; nothing else in this file still does.
 */
it('refuses a file drop into a directory the viewer may see but not write to', function () {
    $file = File::factory()->for($this->source, 'directory')->create(['name' => 'a.txt']);

    $viewOnly = Directory::factory()->create(['name' => 'Read only']);
    grant($viewOnly, $this->user, AccessLevel::View);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->source])
        ->call('dropMove', 'file', $file->id, $viewOnly->id)
        ->assertForbidden();

    expect($file->fresh()->directory_id)->toBe($this->source->id);
});

it('refuses a drop of a file the viewer cannot reach', function () {
    $strangersDirectory = Directory::factory()->create(['name' => 'Theirs']);
    $file = File::factory()->for($strangersDirectory, 'directory')->create(['name' => 'secret.txt']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->source])
        ->call('dropMove', 'file', $file->id, $this->target->id)
        ->assertForbidden();

    expect($file->fresh()->directory_id)->toBe($strangersDirectory->id);
});

it('reports a folder dropped into itself as a refusal rather than an exception', function () {
    $folder = Directory::factory()->for($this->source, 'parent')->create(['name' => 'Sub']);
    $child = Directory::factory()->for($folder, 'parent')->create(['name' => 'Deeper']);

    Livewire::actingAs($this->admin)
        ->test(Browser::class, ['directory' => $this->source])
        ->call('dropMove', 'directory', $folder->id, $child->id)
        ->assertOk()
        ->assertDispatched('drop-refused');

    expect($folder->fresh()->parent_id)->toBe($this->source->id);
});

it('refuses a subject type it does not recognise', function () {
    Livewire::actingAs($this->admin)
        ->test(Browser::class, ['directory' => $this->source])
        ->call('dropMove', 'user', 1, $this->target->id)
        ->assertNotFound();
});

it('refuses a folder drop from someone who may reach both ends but holds no directories.manage', function () {
    // Both layers, visibly independent: the member has Manage on the source
    // AND on the destination, so every access check passes -- and the move is
    // still refused, because the global permission is a separate question.
    $folder = Directory::factory()->for($this->source, 'parent')->create(['name' => 'Sub']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->source])
        ->call('dropMove', 'directory', $folder->id, $this->target->id)
        ->assertForbidden();

    expect($folder->fresh()->parent_id)->toBe($this->source->id);
});

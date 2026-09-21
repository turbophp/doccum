<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Livewire\Files\Browser;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

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

it('lists only directories the viewer may see', function () {
    Livewire::actingAs($this->user)
        ->test(Browser::class)
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

it('answers an unreachable directory the same as a nonexistent one, for the files page', function () {
    // item/reach-oracle-route-binding. /files/{directory?} resolves
    // {directory} by IMPLICIT BINDING, before Browser::mount() runs, so
    // $this->theirs -- which exists and which $this->user has no grant on --
    // used to be found and then 403'd by mount()'s authorize(), while an id
    // no row carries 404'd from the binding. The status told any logged-in
    // user which directory ids exist.
    //
    // OVER HTTP, not Livewire::test(), deliberately: the property is about
    // the RESPONSE STATUS a browser sees, and the route is the only place
    // the binding and the component meet. A Livewire::test() call passes a
    // resolved model straight to mount() and never exercises the binding at
    // all -- it would still reach the new guard, but it could not show the
    // nonexistent half, which has no model to pass.
    //
    // TWO HALVES, because one cannot show that two answers match: the
    // unreachable half alone passed against the old 403 as well.
    $this->actingAs($this->user)
        ->get(route('files.browse', $this->theirs))
        ->assertNotFound();

    $this->actingAs($this->user)
        ->get(route('files.browse', ((int) Directory::query()->max('id')) + 1))
        ->assertNotFound();
});

/**
 * item/files-three-pane (issue #104), closing issue #99 specifically.
 *
 * Written to fail against current main: Browser::render() there resolves
 * the landing pane's 'directories' key as
 * where('parent_id', $this->directory?->getKey())->whereIn('id', $viewable),
 * which at the root of the browser ($this->directory === null) is
 * where('parent_id', IS NULL). A directory granted directly, with no
 * grant anywhere on its own ancestors, is viewable and IS a reach root
 * by DirectoryAccess's own definition (a viewable directory whose parent
 * is not viewable) -- but its parent_id is the ungranted parent's id, not
 * null, so main's where() clause excludes the row before whereIn($viewable)
 * is ever consulted. NestedReachRoot then appears nowhere in the response
 * at all (the only other place a directory name could render, the
 * breadcrumb, is empty here since no directory is selected), so
 * ->assertSee('NestedReachRoot') fails on main. After the fix, the landing
 * pane lists DirectoryAccess::reachTree()'s roots instead, and this passes.
 */
it('reaches a directory granted directly on a nested node from the landing pane, not only by its own URL', function () {
    $ungrantedParent = Directory::factory()->create(['name' => 'UngrantedParent']);
    $nested = Directory::factory()->for($ungrantedParent, 'parent')->create(['name' => 'NestedReachRoot']);

    DirectoryGrant::create([
        'directory_id' => $nested->id,
        'grantee_type' => 'user',
        'grantee_id' => $this->user->id,
        'level' => AccessLevel::View,
    ]);

    Livewire::actingAs($this->user)
        ->test(Browser::class)
        ->assertSee('NestedReachRoot')
        // The ungranted ancestor holds no grant of its own -- it must not
        // leak into the listing just because its child is now reachable.
        ->assertDontSee('UngrantedParent');
});

it('lists files in the current directory', function () {
    File::factory()->for($this->mine, 'directory')->create(['name' => 'Visible.pdf']);
    File::factory()->for($this->theirs, 'directory')->create(['name' => 'Hidden.pdf']);

    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->assertSee('Visible.pdf')
        ->assertDontSee('Hidden.pdf');
});

it('refuses to open a directory the viewer cannot see', function () {
    // 404 SINCE item/reach-oracle-route-binding, where this asserted 403.
    // mount() now refuses an out-of-reach directory before authorize() is
    // reached, so that an id no row carries -- which 404s from the route
    // binding -- cannot be told apart from one that exists out of reach.
    //
    // THIS HALF ALONE DOES NOT STATE THE PROPERTY, and cannot: Livewire::test()
    // hands mount() a resolved model, so there is no way to express "an id
    // that does not exist" here. The pair lives in the HTTP test above
    // ("answers an unreachable directory the same as a nonexistent one, for
    // the files page"), which is also the registered mutation's witness. This
    // one is kept because it exercises the same refusal through the component
    // rather than the route, and it would flip too if the guard were removed.
    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->theirs])
        ->assertNotFound();
});

it('creates a subdirectory', function () {
    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('newDirectoryName', '2024')
        ->call('createDirectory')
        ->assertHasNoErrors();

    expect(Directory::where('name', '2024')->value('parent_id'))->toBe($this->mine->id);
});

it('surfaces a duplicate name as a validation error', function () {
    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('newDirectoryName', '2024')
        ->call('createDirectory')
        ->set('newDirectoryName', '2024')
        ->call('createDirectory')
        ->assertHasErrors('newDirectoryName');
});

it('uploads a file', function () {
    Livewire::actingAs($this->user)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('upload', UploadedFile::fake()->create('Report.pdf', 12, 'application/pdf'))
        ->call('store')
        ->assertHasNoErrors();

    $file = File::where('name', 'Report.pdf')->firstOrFail();

    expect($file->directory_id)->toBe($this->mine->id)
        ->and(Storage::disk('documents')->exists($file->currentVersion->object_key))->toBeTrue();
});

it('refuses an upload without edit access', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('member');
    DirectoryGrant::create([
        'directory_id' => $this->mine->id,
        'grantee_type' => 'user',
        'grantee_id' => $viewer->id,
        'level' => AccessLevel::View,
    ]);

    Livewire::actingAs($viewer)
        ->test(Browser::class, ['directory' => $this->mine])
        ->set('upload', UploadedFile::fake()->create('Report.pdf', 12))
        ->call('store')
        ->assertForbidden();

    // Scoped to the file this test itself would have created, not the whole
    // table: File::count() breaks the moment anything else in the suite
    // legitimately writes a row (CLAUDE.md).
    expect(File::where('name', 'Report.pdf')->count())->toBe(0);
});

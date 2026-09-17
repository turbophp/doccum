<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use App\Search\SearchIndex;
use App\Services\DirectoryAccess;
use App\Services\Search;
use App\Services\SearchIndexer;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Reproduces issue #49: trashing a directory did not hide what is beneath
 * it. Built from the primitives Eloquent's own SoftDeletes already gave
 * `Directory` before this fix -- a plain `->delete()` on the trashed
 * directory, with the live child created BEFORE that call rather than
 * through App\Actions\Directories\TrashDirectory -- so this fails for the
 * reason the issue describes, not merely because that action does not exist
 * yet. It stays this way after the fix, deliberately: TrashDirectory's
 * cascade never gets a chance to touch $this->liveChild here, so if the
 * defensive rule in DirectoryAccess did not exist, nothing in this file
 * would hide it. That is what makes the guard in
 * DirectoryAccess::resolveViewable() and ::resolve() -- not the cascade --
 * the thing this test is checking.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->ancestor = Directory::factory()->create(['name' => 'Ancestor']);
    $this->trashedDir = Directory::factory()->for($this->ancestor, 'parent')->create(['name' => 'Trashed']);

    // Created while $this->trashedDir is still live, then left alone: this
    // is the "live child" the issue's diagnosis is about.
    $this->liveChild = Directory::factory()->for($this->trashedDir, 'parent')->create(['name' => 'LiveChild']);
    $this->file = File::factory()->for($this->liveChild, 'directory')->create(['name' => 'Leaked.pdf']);

    $document = app(SearchIndexer::class)->index($this->file->fresh());
    $document->update(['body' => 'quarterly revenue analysis']);
    app(SearchIndex::class)->put($document->fresh());

    $this->user = User::factory()->create();
    $this->user->assignRole('member');

    DirectoryGrant::create([
        'directory_id' => $this->ancestor->id,
        'grantee_type' => 'user',
        'grantee_id' => $this->user->id,
        'level' => AccessLevel::View,
    ]);

    // The only step that makes this the bug scenario: nothing else here
    // differs from an ordinary, already-passing grant-inheritance test.
    $this->trashedDir->delete();
});

it('excludes a live descendant of a trashed directory from viewableDirectoryIds', function () {
    $ids = app(DirectoryAccess::class)->viewableDirectoryIds($this->user);

    expect($ids)->not->toContain($this->liveChild->id);
});

it('refuses view access on a live descendant of a trashed directory', function () {
    expect(app(DirectoryAccess::class)->can($this->user, $this->liveChild, AccessLevel::View))->toBeFalse()
        ->and($this->user->can('view', $this->liveChild))->toBeFalse();
});

it('excludes a file in a live descendant of a trashed directory from Services\Search results', function () {
    expect(app(Search::class)->for($this->user, 'quarterly'))->toBeEmpty();
});

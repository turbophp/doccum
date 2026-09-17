<?php

declare(strict_types=1);

use App\Actions\Directories\TrashDirectory;
use App\Actions\Files\TrashFile;
use App\Models\Directory;
use App\Models\File;
use App\Models\SearchDocument;
use App\Services\SearchIndexer;

beforeEach(function () {
    $this->root = Directory::factory()->create();
    $this->mid = Directory::factory()->for($this->root, 'parent')->create();
    $this->leaf = Directory::factory()->for($this->mid, 'parent')->create();
    $this->rootFile = File::factory()->for($this->root, 'directory')->create();
    $this->midFile = File::factory()->for($this->mid, 'directory')->create();
    $this->leafFile = File::factory()->for($this->leaf, 'directory')->create();

    foreach ([$this->root, $this->mid, $this->leaf] as $directory) {
        app(SearchIndexer::class)->index($directory);
    }
    foreach ([$this->rootFile, $this->midFile, $this->leafFile] as $file) {
        app(SearchIndexer::class)->index($file);
    }
});

it('soft-deletes every directory in the subtree, root included', function () {
    app(TrashDirectory::class)->handle($this->root->fresh());

    expect(Directory::withTrashed()->findOrFail($this->root->id)->trashed())->toBeTrue()
        ->and(Directory::withTrashed()->findOrFail($this->mid->id)->trashed())->toBeTrue()
        ->and(Directory::withTrashed()->findOrFail($this->leaf->id)->trashed())->toBeTrue();
});

it('soft-deletes every file in the subtree, at every depth', function () {
    app(TrashDirectory::class)->handle($this->root->fresh());

    expect(File::withTrashed()->findOrFail($this->rootFile->id)->trashed())->toBeTrue()
        ->and(File::withTrashed()->findOrFail($this->midFile->id)->trashed())->toBeTrue()
        ->and(File::withTrashed()->findOrFail($this->leafFile->id)->trashed())->toBeTrue();
});

it('is gone from the default query, not merely filtered', function () {
    app(TrashDirectory::class)->handle($this->root->fresh());

    expect(Directory::query()->whereKey($this->leaf->id)->exists())->toBeFalse()
        ->and(File::query()->whereKey($this->leafFile->id)->exists())->toBeFalse();
});

it('stamps every cascaded row with the same deleted_at as the root', function () {
    $trashed = app(TrashDirectory::class)->handle($this->root->fresh());
    $rootDeletedAt = $trashed->deleted_at;

    expect($rootDeletedAt)->not->toBeNull()
        ->and(Directory::withTrashed()->findOrFail($this->mid->id)->deleted_at)->toEqual($rootDeletedAt)
        ->and(Directory::withTrashed()->findOrFail($this->leaf->id)->deleted_at)->toEqual($rootDeletedAt)
        ->and(File::withTrashed()->findOrFail($this->rootFile->id)->deleted_at)->toEqual($rootDeletedAt)
        ->and(File::withTrashed()->findOrFail($this->midFile->id)->deleted_at)->toEqual($rootDeletedAt)
        ->and(File::withTrashed()->findOrFail($this->leafFile->id)->deleted_at)->toEqual($rootDeletedAt);
});

it('forgets the search projection of every directory and file in the subtree', function () {
    app(TrashDirectory::class)->handle($this->root->fresh());

    foreach ([$this->root, $this->mid, $this->leaf] as $directory) {
        expect(SearchDocument::query()->where('subject_type', 'directory')->where('subject_id', $directory->id)->exists())
            ->toBeFalse();
    }

    foreach ([$this->rootFile, $this->midFile, $this->leafFile] as $file) {
        expect(SearchDocument::query()->where('subject_type', 'file')->where('subject_id', $file->id)->exists())
            ->toBeFalse();
    }
});

it('leaves a sibling subtree untouched', function () {
    $other = Directory::factory()->create();
    $otherFile = File::factory()->for($other, 'directory')->create();

    app(TrashDirectory::class)->handle($this->root->fresh());

    expect($other->fresh()->trashed())->toBeFalse()
        ->and($otherFile->fresh()->trashed())->toBeFalse();
});

it('leaves a file that was already trashed independently at its own deleted_at', function () {
    app(TrashFile::class)->handle($this->leafFile);

    // Pinned to a value nothing in this test could coincidentally reproduce,
    // so the assertion below cannot pass by two calls to now() landing in
    // the same second.
    $independentDeletedAt = now()->subDay();
    File::withTrashed()->findOrFail($this->leafFile->id)->forceFill(['deleted_at' => $independentDeletedAt])->saveQuietly();

    $trashed = app(TrashDirectory::class)->handle($this->root->fresh());

    expect(File::withTrashed()->findOrFail($this->leafFile->id)->deleted_at)
        ->toEqual($independentDeletedAt)
        ->not->toEqual($trashed->deleted_at);
});

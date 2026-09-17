<?php

declare(strict_types=1);

use App\Actions\Directories\RestoreDirectory;
use App\Actions\Directories\TrashDirectory;
use App\Actions\Files\TrashFile;
use App\Exceptions\DuplicateDirectoryName;
use App\Models\Directory;
use App\Models\File;

beforeEach(function () {
    $this->parent = Directory::factory()->create();
    $this->root = Directory::factory()->for($this->parent, 'parent')->create();
    $this->mid = Directory::factory()->for($this->root, 'parent')->create();
    $this->leaf = Directory::factory()->for($this->mid, 'parent')->create();
    $this->rootFile = File::factory()->for($this->root, 'directory')->create();
    $this->leafFile = File::factory()->for($this->leaf, 'directory')->create();
});

it('restores every directory and file in the subtree', function () {
    app(TrashDirectory::class)->handle($this->root->fresh());

    $restored = app(RestoreDirectory::class)->handle(Directory::withTrashed()->findOrFail($this->root->id));

    expect($restored->trashed())->toBeFalse()
        ->and($this->mid->fresh()->trashed())->toBeFalse()
        ->and($this->leaf->fresh()->trashed())->toBeFalse()
        ->and(File::query()->whereKey($this->rootFile->id)->exists())->toBeTrue()
        ->and(File::query()->whereKey($this->leafFile->id)->exists())->toBeTrue();
});

it('leaves a file trashed independently before the directory was, trashed', function () {
    app(TrashFile::class)->handle($this->leafFile);
    $independentDeletedAt = File::withTrashed()->findOrFail($this->leafFile->id)->deleted_at;

    app(TrashDirectory::class)->handle($this->root->fresh());
    app(RestoreDirectory::class)->handle(Directory::withTrashed()->findOrFail($this->root->id));

    $file = File::withTrashed()->findOrFail($this->leafFile->id);

    expect($file->trashed())->toBeTrue()
        ->and($file->deleted_at)->toEqual($independentDeletedAt);
});

it('leaves a directory trashed independently before the ancestor was, trashed', function () {
    // Trashed on its own, before the whole subtree above it goes.
    app(TrashDirectory::class)->handle($this->leaf->fresh());
    $independentDeletedAt = Directory::withTrashed()->findOrFail($this->leaf->id)->deleted_at;

    app(TrashDirectory::class)->handle($this->root->fresh());
    app(RestoreDirectory::class)->handle(Directory::withTrashed()->findOrFail($this->root->id));

    $leaf = Directory::withTrashed()->findOrFail($this->leaf->id);

    expect($leaf->trashed())->toBeTrue()
        ->and($leaf->deleted_at)->toEqual($independentDeletedAt)
        ->and($this->mid->fresh()->trashed())->toBeFalse();
});

it('refuses to restore onto a name a live sibling already holds', function () {
    app(TrashDirectory::class)->handle($this->root->fresh());
    Directory::factory()->for($this->parent, 'parent')->create(['name' => (string) $this->root->name]);

    expect(fn () => app(RestoreDirectory::class)->handle(Directory::withTrashed()->findOrFail($this->root->id)))
        ->toThrow(DuplicateDirectoryName::class);
});

it('leaves the directory trashed when restore is refused for a name collision', function () {
    app(TrashDirectory::class)->handle($this->root->fresh());
    Directory::factory()->for($this->parent, 'parent')->create(['name' => (string) $this->root->name]);

    try {
        app(RestoreDirectory::class)->handle(Directory::withTrashed()->findOrFail($this->root->id));
    } catch (DuplicateDirectoryName) {
        // expected
    }

    expect(Directory::withTrashed()->findOrFail($this->root->id)->trashed())->toBeTrue();
});

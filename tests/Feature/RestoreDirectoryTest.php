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

it('tells two cascades apart even when both happen within the same second', function () {
    // The reason trashed_batch exists rather than matching on deleted_at.
    // Two cascades close together in time. Restoring one used to resurrect
    // the other's rows, because deleted_at was the discriminator and two
    // cascades inside one tick of the column's precision are
    // indistinguishable by it.
    //
    // The old deleted_at values are deliberately NOT asserted equal here:
    // that would itself be a race -- true only while both cascades land in
    // the same tick, which is exactly the fragility this test exists to
    // retire. The batch is asserted instead, because it is the thing that
    // actually distinguishes them, at any timing.
    $sibling = Directory::factory()->for($this->parent, 'parent')->create();
    $siblingFile = File::factory()->for($sibling, 'directory')->create();

    app(TrashDirectory::class)->handle($sibling->fresh());
    app(TrashDirectory::class)->handle($this->root->fresh());

    $trashedSibling = Directory::withTrashed()->findOrFail($sibling->id);
    $trashedRoot = Directory::withTrashed()->findOrFail($this->root->id);

    expect($trashedSibling->trashed_batch)->not->toBeNull()
        ->and($trashedRoot->trashed_batch)->not->toBeNull()
        ->and($trashedSibling->trashed_batch)->not->toBe($trashedRoot->trashed_batch);

    app(RestoreDirectory::class)->handle($trashedRoot);

    expect(Directory::withTrashed()->findOrFail($sibling->id)->trashed())->toBeTrue()
        ->and(File::withTrashed()->findOrFail($siblingFile->id)->trashed())->toBeTrue()
        ->and($this->root->fresh()->trashed())->toBeFalse()
        ->and(File::query()->whereKey($this->rootFile->id)->exists())->toBeTrue();
});

it('clears the batch on the rows it restores', function () {
    app(TrashDirectory::class)->handle($this->root->fresh());
    app(RestoreDirectory::class)->handle(Directory::withTrashed()->findOrFail($this->root->id));

    expect($this->root->fresh()->trashed_batch)->toBeNull()
        ->and($this->leaf->fresh()->trashed_batch)->toBeNull()
        ->and(File::query()->findOrFail($this->leafFile->id)->trashed_batch)->toBeNull();
});

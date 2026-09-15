<?php

declare(strict_types=1);

use App\Actions\Directories\MoveDirectory;
use App\Exceptions\CannotMoveDirectoryIntoItself;
use App\Models\Directory;

beforeEach(function () {
    $this->a = Directory::factory()->create();
    $this->b = Directory::factory()->create();
    $this->child = Directory::factory()->for($this->a, 'parent')->create();
    $this->grandchild = Directory::factory()->for($this->child, 'parent')->create();
});

it('rewrites the moved directory path', function () {
    app(MoveDirectory::class)->handle($this->child->fresh(), $this->b->fresh());

    expect($this->child->fresh()->path)->toBe("/{$this->b->id}/{$this->child->id}/")
        ->and($this->child->fresh()->parent_id)->toBe($this->b->id)
        ->and($this->child->fresh()->depth)->toBe(1);
});

it('rewrites descendant paths and depths', function () {
    app(MoveDirectory::class)->handle($this->child->fresh(), $this->b->fresh());

    expect($this->grandchild->fresh()->path)
        ->toBe("/{$this->b->id}/{$this->child->id}/{$this->grandchild->id}/")
        ->and($this->grandchild->fresh()->depth)->toBe(2);
});

it('promotes a directory to the root', function () {
    app(MoveDirectory::class)->handle($this->child->fresh(), null);

    expect($this->child->fresh()->path)->toBe("/{$this->child->id}/")
        ->and($this->child->fresh()->depth)->toBe(0)
        ->and($this->grandchild->fresh()->path)
            ->toBe("/{$this->child->id}/{$this->grandchild->id}/")
        ->and($this->grandchild->fresh()->depth)->toBe(1);
});

it('refuses to move a directory into its own subtree', function () {
    expect(fn () => app(MoveDirectory::class)->handle($this->child->fresh(), $this->grandchild->fresh()))
        ->toThrow(CannotMoveDirectoryIntoItself::class);
});

it('refuses to move a directory into itself', function () {
    expect(fn () => app(MoveDirectory::class)->handle($this->child->fresh(), $this->child->fresh()))
        ->toThrow(CannotMoveDirectoryIntoItself::class);
});

it('leaves unrelated directories untouched', function () {
    $before = $this->a->fresh()->path;
    app(MoveDirectory::class)->handle($this->child->fresh(), $this->b->fresh());

    expect($this->a->fresh()->path)->toBe($before);
});

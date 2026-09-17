<?php

declare(strict_types=1);

use App\Actions\Directories\MoveDirectory;
use App\Exceptions\CannotMoveDirectoryIntoItself;
use App\Exceptions\DuplicateDirectoryName;
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

it('refuses to move onto a name a live sibling already holds in the destination', function () {
    Directory::factory()->for($this->b, 'parent')->create(['name' => 'Taken']);
    $mover = Directory::factory()->for($this->a, 'parent')->create(['name' => 'Taken']);

    expect(fn () => app(MoveDirectory::class)->handle($mover->fresh(), $this->b->fresh()))
        ->toThrow(DuplicateDirectoryName::class);
});

it('refuses to move onto a name that differs only by case from a live sibling in the destination', function () {
    Directory::factory()->for($this->b, 'parent')->create(['name' => 'Report']);
    $mover = Directory::factory()->for($this->a, 'parent')->create(['name' => 'report']);

    expect(fn () => app(MoveDirectory::class)->handle($mover->fresh(), $this->b->fresh()))
        ->toThrow(DuplicateDirectoryName::class);
});

it('allows moving onto a name that differs only by an accent from a live sibling in the destination', function () {
    Directory::factory()->for($this->b, 'parent')->create(['name' => 'Resumes']);
    $mover = Directory::factory()->for($this->a, 'parent')->create(['name' => "R\u{00E9}sum\u{00E9}s"]); // NFC "Résumés"

    $moved = app(MoveDirectory::class)->handle($mover->fresh(), $this->b->fresh());

    expect($moved->parent_id)->toBe($this->b->id);
});

it('refuses to move onto a name a live sibling already holds at the root', function () {
    Directory::factory()->create(['name' => 'Taken']);
    $mover = Directory::factory()->for($this->a, 'parent')->create(['name' => 'Taken']);

    expect(fn () => app(MoveDirectory::class)->handle($mover->fresh(), null))
        ->toThrow(DuplicateDirectoryName::class);
});

it('allows moving onto the name of a trashed sibling in the destination', function () {
    $trashed = Directory::factory()->for($this->b, 'parent')->create(['name' => 'Taken']);
    $trashed->delete();
    $mover = Directory::factory()->for($this->a, 'parent')->create(['name' => 'Taken']);

    $moved = app(MoveDirectory::class)->handle($mover->fresh(), $this->b->fresh());

    expect($moved->parent_id)->toBe($this->b->id);
});

it('leaves the directory in place when the move is refused for a name collision', function () {
    Directory::factory()->for($this->b, 'parent')->create(['name' => 'Taken']);
    $mover = Directory::factory()->for($this->a, 'parent')->create(['name' => 'Taken']);
    $pathBefore = $mover->fresh()->path;

    try {
        app(MoveDirectory::class)->handle($mover->fresh(), $this->b->fresh());
    } catch (DuplicateDirectoryName) {
        // expected
    }

    expect($mover->fresh()->parent_id)->toBe($this->a->id)
        ->and($mover->fresh()->path)->toBe($pathBefore);
});

<?php

declare(strict_types=1);

use App\Models\Directory;

it('gives a root directory a path of its own id', function () {
    $dir = Directory::factory()->create();

    expect($dir->fresh()->path)->toBe("/{$dir->id}/")
        ->and($dir->fresh()->depth)->toBe(0);
});

it('nests a child path beneath its parent', function () {
    $parent = Directory::factory()->create();
    $child = Directory::factory()->for($parent, 'parent')->create();

    expect($child->fresh()->path)->toBe("/{$parent->id}/{$child->id}/")
        ->and($child->fresh()->depth)->toBe(1);
});

it('nests a grandchild two levels deep', function () {
    $root = Directory::factory()->create();
    $mid = Directory::factory()->for($root, 'parent')->create();
    $leaf = Directory::factory()->for($mid, 'parent')->create();

    expect($leaf->fresh()->path)->toBe("/{$root->id}/{$mid->id}/{$leaf->id}/")
        ->and($leaf->fresh()->depth)->toBe(2);
});

it('computes depth from a path', function () {
    expect(Directory::depthFor('/1/'))->toBe(0)
        ->and(Directory::depthFor('/1/5/'))->toBe(1)
        ->and(Directory::depthFor('/1/5/9/'))->toBe(2);
});

it('soft deletes a directory', function () {
    $dir = Directory::factory()->create();
    $dir->delete();

    expect(Directory::count())->toBe(0)
        ->and(Directory::withTrashed()->count())->toBe(1);
});

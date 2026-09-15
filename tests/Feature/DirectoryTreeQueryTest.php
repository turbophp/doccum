<?php

declare(strict_types=1);

use App\Models\Directory;

beforeEach(function () {
    $this->root = Directory::factory()->create();
    $this->mid = Directory::factory()->for($this->root, 'parent')->create();
    $this->leaf = Directory::factory()->for($this->mid, 'parent')->create();
    $this->other = Directory::factory()->create();
});

it('lists descendants excluding itself', function () {
    $ids = $this->root->fresh()->descendants()->pluck('id')->all();

    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain($this->mid->id, $this->leaf->id)
        ->and($ids)->not->toContain($this->root->id);
});

it('returns self plus ancestors, root first', function () {
    expect($this->leaf->fresh()->ancestorIds())
        ->toBe([$this->root->id, $this->mid->id, $this->leaf->id]);
});

it('returns just itself for a root directory', function () {
    expect($this->root->fresh()->ancestorIds())->toBe([$this->root->id]);
});

it('knows whether a node is beneath another', function () {
    expect($this->leaf->fresh()->isDescendantOf($this->root->fresh()))->toBeTrue()
        ->and($this->root->fresh()->isDescendantOf($this->leaf->fresh()))->toBeFalse()
        ->and($this->root->fresh()->isDescendantOf($this->root->fresh()))->toBeFalse()
        ->and($this->other->fresh()->isDescendantOf($this->root->fresh()))->toBeFalse();
});

it('scopes a query to a subtree inclusive of its root', function () {
    $ids = Directory::query()->inSubtreeOf($this->root->fresh())->pluck('id')->all();

    expect($ids)->toHaveCount(3)
        ->and($ids)->not->toContain($this->other->id);
});

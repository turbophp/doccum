<?php

declare(strict_types=1);

use App\Actions\Directories\RenameDirectory;
use App\Exceptions\DuplicateDirectoryName;
use App\Models\Directory;
use App\Models\SearchDocument;

beforeEach(function () {
    $this->parent = Directory::factory()->create();
});

it('renames a directory', function () {
    $dir = Directory::factory()->for($this->parent, 'parent')->create(['name' => 'Old']);

    $renamed = app(RenameDirectory::class)->handle($dir, 'New');

    expect($renamed->name)->toBe('New')
        ->and($dir->fresh()->name)->toBe('New');
});

it('does not rewrite the path or depth of the directory or its descendants', function () {
    // path is a materialised path of ancestor IDS, not names (see
    // Directory::syncPath()), so a rename must leave it, and depth, and
    // every descendant's path, exactly as they were.
    $dir = Directory::factory()->for($this->parent, 'parent')->create(['name' => 'Old']);
    $child = Directory::factory()->for($dir, 'parent')->create();

    $pathBefore = $dir->fresh()->path;
    $depthBefore = $dir->fresh()->depth;
    $childPathBefore = $child->fresh()->path;

    app(RenameDirectory::class)->handle($dir->fresh(), 'New');

    expect($dir->fresh()->path)->toBe($pathBefore)
        ->and($dir->fresh()->depth)->toBe($depthBefore)
        ->and($child->fresh()->path)->toBe($childPathBefore);
});

it('refuses a duplicate name among live siblings', function () {
    Directory::factory()->for($this->parent, 'parent')->create(['name' => 'Taken']);
    $dir = Directory::factory()->for($this->parent, 'parent')->create(['name' => 'Old']);

    expect(fn () => app(RenameDirectory::class)->handle($dir, 'Taken'))
        ->toThrow(DuplicateDirectoryName::class);
});

it('refuses a name that differs only by case from a live sibling', function () {
    Directory::factory()->for($this->parent, 'parent')->create(['name' => 'Report']);
    $dir = Directory::factory()->for($this->parent, 'parent')->create(['name' => 'Old']);

    expect(fn () => app(RenameDirectory::class)->handle($dir, 'report'))
        ->toThrow(DuplicateDirectoryName::class);
});

it('allows a name that differs by an accent from a live sibling', function () {
    Directory::factory()->for($this->parent, 'parent')->create(['name' => 'Resumes']);
    $dir = Directory::factory()->for($this->parent, 'parent')->create(['name' => 'Old']);

    $renamed = app(RenameDirectory::class)->handle($dir, "R\u{00E9}sum\u{00E9}s"); // NFC "Résumés"

    expect($renamed->name)->toBe("R\u{00E9}sum\u{00E9}s");
});

it('allows renaming to its own current name', function () {
    $dir = Directory::factory()->for($this->parent, 'parent')->create(['name' => 'Old']);

    $renamed = app(RenameDirectory::class)->handle($dir, 'Old');

    expect($renamed->name)->toBe('Old');
});

it('allows the same name under a different parent', function () {
    $other = Directory::factory()->create();
    Directory::factory()->for($other, 'parent')->create(['name' => 'Shared']);
    $dir = Directory::factory()->for($this->parent, 'parent')->create(['name' => 'Old']);

    $renamed = app(RenameDirectory::class)->handle($dir, 'Shared');

    expect($renamed->name)->toBe('Shared');
});

it('allows reusing the name of a trashed sibling', function () {
    $trashed = Directory::factory()->for($this->parent, 'parent')->create(['name' => 'Old']);
    $trashed->delete();
    $dir = Directory::factory()->for($this->parent, 'parent')->create(['name' => 'Fresh']);

    $renamed = app(RenameDirectory::class)->handle($dir, 'Old');

    expect($renamed->name)->toBe('Old');
});

it('reindexes the renamed directory for search', function () {
    $dir = Directory::factory()->for($this->parent, 'parent')->create(['name' => 'Old']);

    app(RenameDirectory::class)->handle($dir, 'New');

    expect(
        SearchDocument::where('subject_type', 'directory')->where('subject_id', $dir->id)->value('title')
    )->toBe('New');
});

<?php

declare(strict_types=1);

use App\Actions\Files\RenameFile;
use App\Exceptions\DuplicateFileName;
use App\Models\Directory;
use App\Models\File;
use App\Models\SearchDocument;

beforeEach(function () {
    $this->dir = Directory::factory()->create();
});

it('renames a file', function () {
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt']);

    $renamed = app(RenameFile::class)->handle($file, 'b.txt');

    expect($renamed->name)->toBe('b.txt')
        ->and($file->fresh()->name)->toBe('b.txt');
});

it('refuses a duplicate name among live siblings', function () {
    File::factory()->for($this->dir, 'directory')->create(['name' => 'b.txt']);
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt']);

    expect(fn () => app(RenameFile::class)->handle($file, 'b.txt'))
        ->toThrow(DuplicateFileName::class);
});

it('refuses a name that differs only by case from a live sibling', function () {
    File::factory()->for($this->dir, 'directory')->create(['name' => 'Report.pdf']);
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt']);

    expect(fn () => app(RenameFile::class)->handle($file, 'report.pdf'))
        ->toThrow(DuplicateFileName::class);
});

it('allows a name that differs by an accent from a live sibling', function () {
    File::factory()->for($this->dir, 'directory')->create(['name' => 'resume.pdf']);
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt']);

    $renamed = app(RenameFile::class)->handle($file, "r\u{00E9}sum\u{00E9}.pdf"); // NFC "résumé.pdf"

    expect($renamed->name)->toBe("r\u{00E9}sum\u{00E9}.pdf");
});

it('leaves the file untouched when the rename is refused', function () {
    File::factory()->for($this->dir, 'directory')->create(['name' => 'b.txt']);
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt']);

    try {
        app(RenameFile::class)->handle($file, 'b.txt');
    } catch (DuplicateFileName) {
        // expected
    }

    expect($file->fresh()->name)->toBe('a.txt');
});

it('allows renaming to its own current name', function () {
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt']);

    $renamed = app(RenameFile::class)->handle($file, 'a.txt');

    expect($renamed->name)->toBe('a.txt');
});

it('allows the same name in a different directory', function () {
    $other = Directory::factory()->create();
    File::factory()->for($other, 'directory')->create(['name' => 'a.txt']);
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'b.txt']);

    $renamed = app(RenameFile::class)->handle($file, 'a.txt');

    expect($renamed->name)->toBe('a.txt');
});

it('allows reusing the name of a trashed sibling', function () {
    $trashed = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt']);
    $trashed->delete();
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'b.txt']);

    $renamed = app(RenameFile::class)->handle($file, 'a.txt');

    expect($renamed->name)->toBe('a.txt');
});

it('reindexes the renamed file for search', function () {
    $file = File::factory()->for($this->dir, 'directory')->create(['name' => 'a.txt']);

    app(RenameFile::class)->handle($file, 'b.txt');

    expect(
        SearchDocument::where('subject_type', 'file')->where('subject_id', $file->id)->value('title')
    )->toBe('b.txt');
});

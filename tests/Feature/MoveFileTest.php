<?php

declare(strict_types=1);

use App\Actions\Files\MoveFile;
use App\Exceptions\DuplicateFileName;
use App\Models\Directory;
use App\Models\File;
use App\Models\Property;
use App\Models\PropertyDefinition;
use App\Models\SearchDocument;

beforeEach(function () {
    $this->from = Directory::factory()->create();
    $this->to = Directory::factory()->create();
});

it('moves a file to another directory', function () {
    $file = File::factory()->for($this->from, 'directory')->create(['name' => 'a.txt']);

    $moved = app(MoveFile::class)->handle($file, $this->to);

    expect($moved->directory_id)->toBe($this->to->id)
        ->and($file->fresh()->directory_id)->toBe($this->to->id);
});

it('leaves the file name unchanged', function () {
    $file = File::factory()->for($this->from, 'directory')->create(['name' => 'a.txt']);

    $moved = app(MoveFile::class)->handle($file, $this->to);

    expect($moved->name)->toBe('a.txt');
});

it('refuses a duplicate name in the destination', function () {
    File::factory()->for($this->to, 'directory')->create(['name' => 'a.txt']);
    $file = File::factory()->for($this->from, 'directory')->create(['name' => 'a.txt']);

    expect(fn () => app(MoveFile::class)->handle($file, $this->to))
        ->toThrow(DuplicateFileName::class);
});

it('refuses a name in the destination that differs only by case', function () {
    File::factory()->for($this->to, 'directory')->create(['name' => 'Report.pdf']);
    $file = File::factory()->for($this->from, 'directory')->create(['name' => 'report.pdf']);

    expect(fn () => app(MoveFile::class)->handle($file, $this->to))
        ->toThrow(DuplicateFileName::class);
});

it('allows a name that differs by an accent from a live sibling in the destination', function () {
    File::factory()->for($this->to, 'directory')->create(['name' => 'resume.pdf']);
    $file = File::factory()->for($this->from, 'directory')->create(['name' => "r\u{00E9}sum\u{00E9}.pdf"]);

    $moved = app(MoveFile::class)->handle($file, $this->to);

    expect($moved->directory_id)->toBe($this->to->id);
});

it('leaves the file in its source directory when the move is refused', function () {
    File::factory()->for($this->to, 'directory')->create(['name' => 'a.txt']);
    $file = File::factory()->for($this->from, 'directory')->create(['name' => 'a.txt']);

    try {
        app(MoveFile::class)->handle($file, $this->to);
    } catch (DuplicateFileName) {
        // expected
    }

    expect($file->fresh()->directory_id)->toBe($this->from->id);
});

it('allows moving when only the source directory holds that name', function () {
    File::factory()->for($this->from, 'directory')->create(['name' => 'b.txt']);
    $file = File::factory()->for($this->from, 'directory')->create(['name' => 'a.txt']);

    $moved = app(MoveFile::class)->handle($file, $this->to);

    expect($moved->directory_id)->toBe($this->to->id);
});

it('allows moving onto the name of a trashed file in the destination', function () {
    $trashed = File::factory()->for($this->to, 'directory')->create(['name' => 'a.txt']);
    $trashed->delete();
    $file = File::factory()->for($this->from, 'directory')->create(['name' => 'a.txt']);

    $moved = app(MoveFile::class)->handle($file, $this->to);

    expect($moved->directory_id)->toBe($this->to->id);
});

it('reindexes the moved file and its properties for search', function () {
    $file = File::factory()->for($this->from, 'directory')->create(['name' => 'a.txt']);
    $property = Property::factory()->create([
        'property_definition_id' => PropertyDefinition::factory(),
        'subject_type' => 'file',
        'subject_id' => $file->getKey(),
    ]);

    app(MoveFile::class)->handle($file, $this->to);

    $fileDoc = SearchDocument::where('subject_type', 'file')->where('subject_id', $file->id)->first();
    $propertyDoc = SearchDocument::where('subject_type', 'property')->where('subject_id', $property->id)->first();

    expect($fileDoc->ancestor_ids)->toBe($this->to->ancestorIds())
        ->and($propertyDoc->ancestor_ids)->toBe($this->to->ancestorIds());
});

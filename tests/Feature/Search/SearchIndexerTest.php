<?php

declare(strict_types=1);

use App\Enums\ExtractionStatus;
use App\Enums\PropertyDataType;
use App\Models\Directory;
use App\Models\File;
use App\Models\FileText;
use App\Models\Property;
use App\Models\PropertyDefinition;
use App\Models\SearchDocument;
use App\Models\User;
use App\Services\SearchIndexer;

beforeEach(function () {
    $this->root = Directory::factory()->create(['name' => 'Contracts']);
    $this->child = Directory::factory()->for($this->root, 'parent')->create(['name' => '2026']);
});

it('indexes a directory by name', function () {
    $doc = app(SearchIndexer::class)->index($this->child);

    expect($doc->title)->toBe('2026')
        ->and($doc->subject_type)->toBe('directory')
        ->and($doc->subject_id)->toBe($this->child->id);
});

it('records every ancestor so access can be filtered in the query', function () {
    $doc = app(SearchIndexer::class)->index($this->child);

    expect($doc->ancestor_ids)->toBe([$this->root->id, $this->child->id]);
});

it('indexes a file with its extracted text', function () {
    $file = File::factory()->for($this->child, 'directory')->create(['name' => 'Lease.pdf']);
    $version = $file->versions()->create([
        'version_number' => 1, 'object_key' => 'k', 'size' => 1,
        'mime' => 'application/pdf', 'checksum' => str_repeat('a', 64),
        'uploaded_by' => User::factory()->create()->id,
    ]);
    $file->update(['current_version_id' => $version->id]);
    FileText::create([
        'file_version_id' => $version->id, 'status' => ExtractionStatus::Done,
        'text' => 'the tenant shall maintain the premises', 'chars' => 38,
    ]);

    $doc = app(SearchIndexer::class)->index($file->fresh());

    expect($doc->title)->toBe('Lease.pdf')
        ->and($doc->body)->toContain('tenant shall maintain')
        ->and($doc->directory_id)->toBe($this->child->id)
        ->and($doc->extension)->toBe('pdf');
});

it('flattens property values into the body so a document is found by its metadata', function () {
    $file = File::factory()->for($this->child, 'directory')->create(['name' => 'Invoice.pdf']);
    $definition = PropertyDefinition::factory()->create([
        'key' => 'supplier', 'label' => 'Supplier', 'data_type' => PropertyDataType::String_,
    ]);
    Property::for($file, $definition)->setValue('Acme Industries');

    $doc = app(SearchIndexer::class)->index($file->fresh());

    expect($doc->body)->toContain('Acme Industries')
        ->and($doc->body)->toContain('Supplier');
});

it('indexes a property in its own right', function () {
    $definition = PropertyDefinition::factory()->create([
        'key' => 'supplier', 'label' => 'Supplier', 'data_type' => PropertyDataType::String_,
    ]);
    $file = File::factory()->for($this->child, 'directory')->create();
    $property = Property::for($file, $definition)->setValue('Acme Industries');

    $doc = app(SearchIndexer::class)->index($property->fresh());

    expect($doc->subject_type)->toBe('property')
        ->and($doc->body)->toContain('Acme Industries')
        // It inherits the file's position, so it filters by access identically.
        ->and($doc->ancestor_ids)->toBe([$this->root->id, $this->child->id]);
});

it('replaces rather than duplicating when reindexed', function () {
    app(SearchIndexer::class)->index($this->child);
    $this->child->update(['name' => 'Renamed']);
    app(SearchIndexer::class)->index($this->child->fresh());

    expect(SearchDocument::count())->toBe(1)
        ->and(SearchDocument::first()->title)->toBe('Renamed');
});

it('forgets a subject', function () {
    app(SearchIndexer::class)->index($this->child);
    app(SearchIndexer::class)->forget($this->child);

    expect(SearchDocument::count())->toBe(0);
});

it('carries the period so results can be scoped to a year', function () {
    $file = File::factory()->for($this->child, 'directory')->create([
        'period_year' => 2024, 'period_month' => 3,
    ]);

    $doc = app(SearchIndexer::class)->index($file->fresh());

    expect($doc->period_year)->toBe(2024)->and($doc->period_month)->toBe(3);
});

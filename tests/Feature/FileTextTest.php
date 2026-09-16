<?php

declare(strict_types=1);

use App\Enums\ExtractionStatus;
use App\Models\FileText;
use App\Models\FileVersion;
use Illuminate\Database\QueryException;

it('stores text against one version', function () {
    $version = FileVersion::factory()->create();

    FileText::create([
        'file_version_id' => $version->id,
        'status' => ExtractionStatus::Done,
        'extractor' => 'pdftotext',
        'text' => 'the contents',
        'chars' => 12,
    ]);

    expect($version->fresh()->text->text)->toBe('the contents')
        ->and($version->fresh()->text->status)->toBe(ExtractionStatus::Done);
});

it('holds one row per version', function () {
    $version = FileVersion::factory()->create();
    FileText::factory()->for($version, 'version')->create();

    expect(fn () => FileText::factory()->for($version, 'version')->create())
        ->toThrow(QueryException::class);
});

it('records a failure with its reason', function () {
    $version = FileVersion::factory()->create();

    FileText::create([
        'file_version_id' => $version->id,
        'status' => ExtractionStatus::Failed,
        'error' => 'pdftotext exited 1',
    ]);

    expect($version->fresh()->text->status)->toBe(ExtractionStatus::Failed)
        ->and($version->fresh()->text->error)->toContain('pdftotext');
});

it('exposes the current version text through the file', function () {
    $version = FileVersion::factory()->create();
    $file = $version->file;
    $file->update(['current_version_id' => $version->id]);

    FileText::create([
        'file_version_id' => $version->id,
        'status' => ExtractionStatus::Done,
        'text' => 'searchable words',
        'chars' => 16,
    ]);

    expect($file->fresh()->extractedText())->toBe('searchable words');
});

it('returns null when the current version has no text yet', function () {
    $version = FileVersion::factory()->create();
    $file = $version->file;
    $file->update(['current_version_id' => $version->id]);

    expect($file->fresh()->extractedText())->toBeNull();
});

it('goes away with its version', function () {
    $version = FileVersion::factory()->create();
    FileText::factory()->for($version, 'version')->create();

    $version->delete();

    expect(FileText::count())->toBe(0);
});

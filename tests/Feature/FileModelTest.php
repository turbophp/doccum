<?php

declare(strict_types=1);

use App\Models\Directory;
use App\Models\File;
use App\Models\FileVersion;
use Illuminate\Database\QueryException;

it('assigns a uuid on creation', function () {
    $file = File::factory()->create();

    expect($file->uuid)->toBeString()->toHaveLength(36);
});

it('derives its period from the creation time', function () {
    $this->travelTo('2024-03-17 10:00:00');

    $file = File::factory()->create();

    expect($file->period_year)->toBe(2024)
        ->and($file->period_month)->toBe(3);
});

it('belongs to a directory', function () {
    $dir = Directory::factory()->create();
    $file = File::factory()->for($dir, 'directory')->create();

    expect($file->directory->id)->toBe($dir->id);
});

it('has many versions and resolves the current one', function () {
    $file = File::factory()->create();
    $v1 = FileVersion::factory()->for($file)->create(['version_number' => 1]);
    $v2 = FileVersion::factory()->for($file)->create(['version_number' => 2]);

    $file->update(['current_version_id' => $v2->id]);

    expect($file->fresh()->versions)->toHaveCount(2)
        ->and($file->fresh()->currentVersion->id)->toBe($v2->id)
        ->and($v1->file->id)->toBe($file->id);
});

it('refuses duplicate version numbers for one file', function () {
    $file = File::factory()->create();
    FileVersion::factory()->for($file)->create(['version_number' => 1]);

    expect(fn () => FileVersion::factory()->for($file)->create(['version_number' => 1]))
        ->toThrow(QueryException::class);
});

it('soft deletes a file', function () {
    $file = File::factory()->create();
    $file->delete();

    expect(File::count())->toBe(0)
        ->and(File::withTrashed()->count())->toBe(1);
});

it('defaults legal hold to false', function () {
    expect(File::factory()->create()->legal_hold)->toBeFalse();
});

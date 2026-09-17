<?php

declare(strict_types=1);

use App\Actions\Files\MoveFile;
use App\Actions\Files\RenameFile;
use App\Actions\Files\TrashFile;
use App\Exceptions\DuplicateFileName;
use App\Exceptions\FileIsUnderLegalHold;
use App\Exceptions\PeriodIsArchived;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;

it('refuses to trash a file under legal hold', function () {
    $file = File::factory()->create(['legal_hold' => true]);

    expect(fn () => app(TrashFile::class)->handle($file))
        ->toThrow(FileIsUnderLegalHold::class);

    expect(File::withTrashed()->find($file->id)->trashed())->toBeFalse();
});

it('trashes a file that is not held', function () {
    $file = File::factory()->create(['legal_hold' => false]);

    app(TrashFile::class)->handle($file);

    expect(File::withTrashed()->find($file->id)->trashed())->toBeTrue();
});

it('refuses to trash a file in an archived period', function () {
    $file = File::factory()->create(['period_year' => 2024, 'period_month' => 3]);
    ArchivePeriod::factory()->create(['year' => 2024, 'month' => 3, 'archived_at' => now()]);

    expect(fn () => app(TrashFile::class)->handle($file))
        ->toThrow(PeriodIsArchived::class);
});

it('refuses to rename a file in an archived period', function () {
    $file = File::factory()->create(['period_year' => 2024, 'period_month' => 3, 'name' => 'a.txt']);
    ArchivePeriod::factory()->create(['year' => 2024, 'month' => 3, 'archived_at' => now()]);

    expect(fn () => app(RenameFile::class)->handle($file, 'b.txt'))
        ->toThrow(PeriodIsArchived::class);

    expect($file->fresh()->name)->toBe('a.txt');
});

it('refuses to move a file out of an archived period', function () {
    $file = File::factory()->create(['period_year' => 2024, 'period_month' => 3]);
    $elsewhere = Directory::factory()->create();
    ArchivePeriod::factory()->create(['year' => 2024, 'month' => 3, 'archived_at' => now()]);

    expect(fn () => app(MoveFile::class)->handle($file, $elsewhere))
        ->toThrow(PeriodIsArchived::class);
});

it('renames and moves a file in an open period', function () {
    $file = File::factory()->create(['period_year' => 2026, 'period_month' => 9, 'name' => 'a.txt']);
    $to = Directory::factory()->create();

    app(RenameFile::class)->handle($file, 'b.txt');
    app(MoveFile::class)->handle($file->fresh(), $to);

    expect($file->fresh()->name)->toBe('b.txt')
        ->and($file->fresh()->directory_id)->toBe($to->id);
});

it('refuses to rename onto a name already taken in the directory', function () {
    $dir = Directory::factory()->create();
    File::factory()->for($dir, 'directory')->create(['name' => 'taken.txt']);
    $file = File::factory()->for($dir, 'directory')->create(['name' => 'mine.txt']);

    expect(fn () => app(RenameFile::class)->handle($file, 'taken.txt'))
        ->toThrow(DuplicateFileName::class);
});

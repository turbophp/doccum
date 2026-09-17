<?php

declare(strict_types=1);

use App\Actions\Files\PurgeFile;
use App\Exceptions\FileUnderLegalHold;
use App\Exceptions\PeriodIsArchived;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\Property;
use App\Models\PropertyDefinition;
use App\Models\SearchDocument;
use App\Services\SearchIndexer;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    $this->travelTo('2026-06-01');
    $this->dir = Directory::factory()->create();
});

function purgeable(array $attributes = []): File
{
    return File::factory()->for(test()->dir, 'directory')->create(
        ['period_year' => 2026, 'period_month' => 1] + $attributes
    );
}

it('deletes the file row for good', function () {
    $file = purgeable();

    app(PurgeFile::class)->handle($file);

    expect(File::withTrashed()->find($file->id))->toBeNull();
});

it('removes every version object through DocumentStorage, not merely the current one', function () {
    $file = purgeable();
    $v1 = FileVersion::factory()->for($file)->create([
        'version_number' => 1, 'object_key' => 'files/2026/01/'.$file->uuid.'/v1/a.txt',
    ]);
    $v2 = FileVersion::factory()->for($file)->create([
        'version_number' => 2, 'object_key' => 'files/2026/01/'.$file->uuid.'/v2/a.txt',
    ]);
    Storage::disk('documents')->put($v1->object_key, 'one');
    Storage::disk('documents')->put($v2->object_key, 'two');

    app(PurgeFile::class)->handle($file);

    // Asserted through the disk, not the row count: a purge that drops
    // rows and leaves bytes behind is exactly the failure this covers.
    expect(Storage::disk('documents')->exists($v1->object_key))->toBeFalse()
        ->and(Storage::disk('documents')->exists($v2->object_key))->toBeFalse()
        ->and(FileVersion::where('file_id', $file->id)->count())->toBe(0);
});

it('leaves another file untouched, rows and objects both', function () {
    $file = purgeable();
    $keep = purgeable();
    $keepVersion = FileVersion::factory()->for($keep)->create([
        'version_number' => 1, 'object_key' => 'files/2026/01/'.$keep->uuid.'/v1/keep.txt',
    ]);
    Storage::disk('documents')->put($keepVersion->object_key, 'contents');

    app(PurgeFile::class)->handle($file);

    expect(File::withTrashed()->find($keep->id))->not->toBeNull()
        ->and(Storage::disk('documents')->exists($keepVersion->object_key))->toBeTrue();
});

it('takes properties and the search projection with it', function () {
    $file = purgeable();
    $property = Property::factory()->create([
        'property_definition_id' => PropertyDefinition::factory(),
        'subject_type' => 'file',
        'subject_id' => $file->getKey(),
    ]);
    app(SearchIndexer::class)->index($file->refresh());

    app(PurgeFile::class)->handle($file);

    expect(Property::find($property->id))->toBeNull()
        ->and(SearchDocument::where('subject_type', 'file')->where('subject_id', $file->id)->count())->toBe(0)
        ->and(SearchDocument::where('subject_type', 'property')->where('subject_id', $property->id)->count())->toBe(0);
});

it('purges a file that was already trashed, the object it kept alive included', function () {
    $file = purgeable();
    $version = FileVersion::factory()->for($file)->create([
        'version_number' => 1, 'object_key' => 'files/2026/01/'.$file->uuid.'/v1/a.txt',
    ]);
    Storage::disk('documents')->put($version->object_key, 'contents');
    $file->delete();

    app(PurgeFile::class)->handle(File::withTrashed()->findOrFail($file->id));

    expect(File::withTrashed()->find($file->id))->toBeNull()
        ->and(Storage::disk('documents')->exists($version->object_key))->toBeFalse();
});

it('refuses to purge a file under legal hold', function () {
    $file = purgeable(['legal_hold' => true]);
    $version = FileVersion::factory()->for($file)->create([
        'version_number' => 1, 'object_key' => 'files/2026/01/'.$file->uuid.'/v1/a.txt',
    ]);
    Storage::disk('documents')->put($version->object_key, 'contents');

    expect(fn () => app(PurgeFile::class)->handle($file))
        ->toThrow(FileUnderLegalHold::class);

    expect(File::withTrashed()->find($file->id))->not->toBeNull()
        ->and(Storage::disk('documents')->exists($version->object_key))->toBeTrue();
});

it('refuses to purge a file whose period is archived', function () {
    ArchivePeriod::factory()->create(['year' => 2026, 'month' => 1, 'archived_at' => now()]);
    $file = purgeable();
    $version = FileVersion::factory()->for($file)->create([
        'version_number' => 1, 'object_key' => 'files/2026/01/'.$file->uuid.'/v1/a.txt',
    ]);
    Storage::disk('documents')->put($version->object_key, 'contents');

    expect(fn () => app(PurgeFile::class)->handle($file))
        ->toThrow(PeriodIsArchived::class);

    expect(File::withTrashed()->find($file->id))->not->toBeNull()
        ->and(Storage::disk('documents')->exists($version->object_key))->toBeTrue();
});

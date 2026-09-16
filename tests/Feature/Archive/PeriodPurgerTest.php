<?php

declare(strict_types=1);

use App\Exceptions\PeriodNotPurgeable;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;
use App\Models\FileText;
use App\Models\FileVersion;
use App\Models\Property;
use App\Models\PropertyDefinition;
use App\Models\SearchDocument;
use App\Search\SearchIndex;
use App\Services\PeriodPurger;
use App\Services\SearchIndexer;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    $this->travelTo('2026-06-01');
    $this->dir = Directory::factory()->create();
});

function fileIn(int $year, int $month, array $attributes = []): File
{
    return File::factory()->for(test()->dir, 'directory')->create(
        ['period_year' => $year, 'period_month' => $month, 'size' => 100] + $attributes
    );
}

function archived(int $year, int $month): ArchivePeriod
{
    return ArchivePeriod::factory()->create([
        'year' => $year, 'month' => $month, 'archived_at' => now()->subYears(3),
    ]);
}

it('refuses a period that was never archived', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    fileIn(2020, 3);

    expect(fn () => app(PeriodPurger::class)->purge(2020, 3))
        ->toThrow(PeriodNotPurgeable::class);

    expect(File::withTrashed()->count())->toBe(1);
});

it('refuses a period inside the retention window', function () {
    config()->set('doccum.retention.purge_after_years', 7);
    archived(2024, 3);
    fileIn(2024, 3);

    expect(fn () => app(PeriodPurger::class)->purge(2024, 3))
        ->toThrow(PeriodNotPurgeable::class);

    expect(File::withTrashed()->count())->toBe(1);
});

it('refuses every period when no retention window is configured', function () {
    config()->set('doccum.retention.purge_after_years', null);
    archived(2020, 3);
    fileIn(2020, 3);

    // Unset means "nobody has decided how long documents are kept". Treating
    // that as "keep nothing" would make an unconfigured instance purge.
    expect(fn () => app(PeriodPurger::class)->purge(2020, 3))
        ->toThrow(PeriodNotPurgeable::class);

    expect(File::withTrashed()->count())->toBe(1);
});

it('refuses when a file in the period is under legal hold', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    fileIn(2020, 3);
    fileIn(2020, 3, ['legal_hold' => true]);

    expect(fn () => app(PeriodPurger::class)->purge(2020, 3))
        ->toThrow(PeriodNotPurgeable::class);

    // Nothing at all goes, not merely the held file: a partial purge of a
    // period under hold is exactly what a hold exists to prevent.
    expect(File::withTrashed()->count())->toBe(2);
});

it('sees a legal hold on a trashed file too', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    $held = fileIn(2020, 3, ['legal_hold' => true]);
    $held->delete();

    // Trash keeps the object, so purging would still destroy the bytes the
    // hold exists to preserve.
    expect(app(PeriodPurger::class)->plan(2020, 3)->purgeable)->toBeFalse();
});

it('names the blockers rather than just refusing', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    fileIn(2020, 3, ['legal_hold' => true, 'name' => 'Held.pdf']);

    $plan = app(PeriodPurger::class)->plan(2020, 3);

    expect($plan->purgeable)->toBeFalse()
        ->and($plan->blockers)->not->toBeEmpty()
        ->and(implode(' ', $plan->blockers))->toContain('legal hold');
});

it('carries the blockers on the exception it throws', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    fileIn(2020, 3, ['legal_hold' => true]);

    try {
        app(PeriodPurger::class)->purge(2020, 3);
    } catch (PeriodNotPurgeable $e) {
        expect(implode(' ', $e->blockers))->toContain('legal hold')
            ->and($e->getMessage())->toContain('legal hold');

        return;
    }

    $this->fail('The purge was not refused.');
});

it('reports what would go without touching anything', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    fileIn(2020, 3);
    fileIn(2020, 3);

    $plan = app(PeriodPurger::class)->plan(2020, 3);

    expect($plan->purgeable)->toBeTrue()
        ->and($plan->fileCount)->toBe(2)
        ->and($plan->byteCount)->toBe(200)
        ->and(File::withTrashed()->count())->toBe(2);
});

it('counts trashed files in the plan, because their objects still cost storage', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    fileIn(2020, 3);
    fileIn(2020, 3)->delete();

    expect(app(PeriodPurger::class)->plan(2020, 3)->fileCount)->toBe(2);
});

it('purges a period that passes every guard', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    $file = fileIn(2020, 3);
    $keep = fileIn(2025, 3);

    $report = app(PeriodPurger::class)->purge(2020, 3);

    expect($report->fileCount)->toBe(1)
        ->and(File::withTrashed()->find($file->id))->toBeNull()
        ->and(File::withTrashed()->find($keep->id))->not->toBeNull()
        ->and(ArchivePeriod::where('year', 2020)->where('month', 3)->value('purged_at'))->not->toBeNull();
});

it('purges a trashed file, whose object is exactly what is being reclaimed', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    $trashed = fileIn(2020, 3);
    $trashed->delete();

    app(PeriodPurger::class)->purge(2020, 3);

    expect(File::withTrashed()->find($trashed->id))->toBeNull();
});

it('purges a whole year when no month is given', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    ArchivePeriod::factory()->create([
        'year' => 2020, 'month' => null, 'archived_at' => now()->subYears(3),
    ]);
    $march = fileIn(2020, 3);
    $july = fileIn(2020, 7);
    $keep = fileIn(2021, 1);

    $report = app(PeriodPurger::class)->purge(2020);

    expect($report->fileCount)->toBe(2)
        ->and(File::withTrashed()->find($march->id))->toBeNull()
        ->and(File::withTrashed()->find($july->id))->toBeNull()
        ->and(File::withTrashed()->find($keep->id))->not->toBeNull();
});

it('takes the versions, text, properties and search rows with it', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    $file = fileIn(2020, 3);
    $version = FileVersion::factory()->for($file)->create(['version_number' => 1]);
    $text = FileText::factory()->for($version, 'version')->create(['text' => 'secret']);
    $property = Property::factory()->create([
        'property_definition_id' => PropertyDefinition::factory(),
        'subject_type' => 'file',
        'subject_id' => $file->getKey(),
    ]);

    app(PeriodPurger::class)->purge(2020, 3);

    expect(FileVersion::where('file_id', $file->id)->count())->toBe(0)
        ->and(FileText::find($text->id))->toBeNull()
        ->and(Property::find($property->id))->toBeNull()
        ->and(SearchDocument::where('subject_type', 'file')->where('subject_id', $file->id)->count())->toBe(0)
        ->and(SearchDocument::where('subject_type', 'property')->where('subject_id', $property->id)->count())->toBe(0);
});

it('leaves nothing purged behind in the keyword index', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    $file = fileIn(2020, 3, ['name' => 'Zagglefrotz.pdf']);
    app(SearchIndexer::class)->index($file->refresh());

    app(PeriodPurger::class)->purge(2020, 3);

    // A projection row deleted but left in the index still matches, and then
    // vanishes from the results -- which looks exactly like corruption.
    expect(app(SearchIndex::class)->search('Zagglefrotz', [$this->dir->getKey()]))->toBeEmpty();
});

it('leaves the directory structure standing', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    fileIn(2020, 3);

    app(PeriodPurger::class)->purge(2020, 3);

    expect(Directory::find($this->dir->id))->not->toBeNull();
});

it('deletes the objects it accounted for', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    $file = fileIn(2020, 3);
    $version = FileVersion::factory()->for($file)->create([
        'version_number' => 1, 'object_key' => 'files/2020/03/'.$file->uuid.'/v1/a.txt',
    ]);
    Storage::disk('documents')->put($version->object_key, 'contents');

    app(PeriodPurger::class)->purge(2020, 3);

    // Objects without rows is a leak that only shows up as a storage bill.
    expect(Storage::disk('documents')->exists($version->object_key))->toBeFalse();
});

it('leaves another period objects alone', function () {
    config()->set('doccum.retention.purge_after_years', 1);
    archived(2020, 3);
    $keep = fileIn(2025, 3);
    $keepVersion = FileVersion::factory()->for($keep)->create([
        'version_number' => 1, 'object_key' => 'files/2025/03/'.$keep->uuid.'/v1/keep.txt',
    ]);
    Storage::disk('documents')->put($keepVersion->object_key, 'contents');

    app(PeriodPurger::class)->purge(2020, 3);

    expect(Storage::disk('documents')->exists($keepVersion->object_key))->toBeTrue();
});

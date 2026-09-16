<?php

declare(strict_types=1);

use App\Models\ArchivePeriod;
use App\Models\File;
use App\Services\PeriodCloser;

it('records what a period holds when it closes', function () {
    File::factory()->count(3)->create(['period_year' => 2024, 'period_month' => 3, 'size' => 1000]);
    File::factory()->create(['period_year' => 2024, 'period_month' => 4, 'size' => 500]);

    $period = app(PeriodCloser::class)->close(2024, 3);

    expect($period->file_count)->toBe(3)
        ->and($period->byte_count)->toBe(3000)
        ->and($period->isArchived())->toBeTrue();
});

it('counts trashed files too, because their objects still occupy storage', function () {
    File::factory()->count(2)->create(['period_year' => 2024, 'period_month' => 3, 'size' => 100]);
    $trashed = File::factory()->create(['period_year' => 2024, 'period_month' => 3, 'size' => 100]);
    $trashed->delete();

    expect(app(PeriodCloser::class)->close(2024, 3)->file_count)->toBe(3);
});

it('is idempotent', function () {
    File::factory()->create(['period_year' => 2024, 'period_month' => 3, 'size' => 100]);

    app(PeriodCloser::class)->close(2024, 3);
    app(PeriodCloser::class)->close(2024, 3);

    expect(ArchivePeriod::where('year', 2024)->where('month', 3)->count())->toBe(1);
});

it('refuses to close a period that has not ended', function () {
    $this->travelTo('2026-05-10');

    expect(fn () => app(PeriodCloser::class)->close(2026, 5))
        ->toThrow(InvalidArgumentException::class);
});

it('closes every finished period at once', function () {
    $this->travelTo('2026-05-10');
    File::factory()->create(['period_year' => 2026, 'period_month' => 3, 'size' => 10]);
    File::factory()->create(['period_year' => 2026, 'period_month' => 4, 'size' => 20]);
    File::factory()->create(['period_year' => 2026, 'period_month' => 5, 'size' => 30]);

    $closed = app(PeriodCloser::class)->closeFinished();

    // March and April have ended; May has not.
    expect($closed->pluck('month')->all())->toEqualCanonicalizing([3, 4]);
});

it('does not reopen a purged period', function () {
    $period = ArchivePeriod::factory()->create([
        'year' => 2024, 'month' => 3, 'archived_at' => now(), 'purged_at' => now(),
    ]);

    app(PeriodCloser::class)->close(2024, 3);

    expect($period->fresh()->purged_at)->not->toBeNull();
});

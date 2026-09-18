<?php

declare(strict_types=1);

use App\Models\ArchivePeriod;
use App\Models\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
});

it('closes finished periods on a schedule', function () {
    $this->travelTo('2026-05-10');
    File::factory()->create(['period_year' => 2026, 'period_month' => 3, 'size' => 10]);

    $this->artisan('doccum:close-periods')->assertSuccessful();

    expect(ArchivePeriod::where('year', 2026)->where('month', 3)->value('archived_at'))->not->toBeNull();
});

it('is a dry run unless told otherwise', function () {
    $this->travelTo('2026-06-01');
    config()->set('doccum.settings.retention.purge_after_years', 1);
    ArchivePeriod::factory()->create(['year' => 2020, 'month' => 3, 'archived_at' => now()->subYears(3)]);
    File::factory()->create(['period_year' => 2020, 'period_month' => 3, 'size' => 100]);

    $this->artisan('doccum:purge-period 2020 3')->assertSuccessful();

    // The default must never destroy anything: an operator reaching for this
    // command is usually finding out what it would do.
    expect(File::withTrashed()->count())->toBe(1);
});

it('purges when explicitly told to', function () {
    $this->travelTo('2026-06-01');
    config()->set('doccum.settings.retention.purge_after_years', 1);
    ArchivePeriod::factory()->create(['year' => 2020, 'month' => 3, 'archived_at' => now()->subYears(3)]);
    File::factory()->create(['period_year' => 2020, 'period_month' => 3, 'size' => 100]);

    $this->artisan('doccum:purge-period 2020 3 --force')->assertSuccessful();

    expect(File::withTrashed()->count())->toBe(0);
});

it('explains why it will not purge', function () {
    $this->travelTo('2026-06-01');
    config()->set('doccum.settings.retention.purge_after_years', 1);
    ArchivePeriod::factory()->create(['year' => 2020, 'month' => 3, 'archived_at' => now()->subYears(3)]);
    File::factory()->create([
        'period_year' => 2020, 'period_month' => 3, 'size' => 100, 'legal_hold' => true,
    ]);

    $this->artisan('doccum:purge-period 2020 3 --force')
        ->expectsOutputToContain('legal hold')
        ->assertFailed();

    expect(File::withTrashed()->count())->toBe(1);
});

it('explains why it will not purge on a dry run too', function () {
    $this->travelTo('2026-06-01');
    config()->set('doccum.settings.retention.purge_after_years', 1);
    ArchivePeriod::factory()->create(['year' => 2020, 'month' => 3, 'archived_at' => now()->subYears(3)]);
    File::factory()->create([
        'period_year' => 2020, 'period_month' => 3, 'size' => 100, 'legal_hold' => true,
    ]);

    $this->artisan('doccum:purge-period 2020 3')
        ->expectsOutputToContain('legal hold')
        ->assertSuccessful();
});

it('does nothing on a schedule unless automatic purging is switched on', function () {
    $this->travelTo('2026-06-01');
    config()->set('doccum.settings.retention.purge_after_years', 1);
    config()->set('doccum.settings.retention.auto_purge', false);
    ArchivePeriod::factory()->create(['year' => 2020, 'month' => 3, 'archived_at' => now()->subYears(3)]);
    File::factory()->create(['period_year' => 2020, 'period_month' => 3, 'size' => 100]);

    $this->artisan('doccum:purge-expired')->assertSuccessful();

    // Reports candidates, deletes nothing. Scheduled deletion is opt-in
    // because nobody is watching when it runs.
    expect(File::withTrashed()->count())->toBe(1);
});

it('purges expired periods once switched on', function () {
    $this->travelTo('2026-06-01');
    config()->set('doccum.settings.retention.purge_after_years', 1);
    config()->set('doccum.settings.retention.auto_purge', true);
    ArchivePeriod::factory()->create(['year' => 2020, 'month' => 3, 'archived_at' => now()->subYears(3)]);
    File::factory()->create(['period_year' => 2020, 'period_month' => 3, 'size' => 100]);

    $this->artisan('doccum:purge-expired')->assertSuccessful();

    expect(File::withTrashed()->count())->toBe(0);
});

it('leaves a held period alone even when automatic purging is on', function () {
    $this->travelTo('2026-06-01');
    config()->set('doccum.settings.retention.purge_after_years', 1);
    config()->set('doccum.settings.retention.auto_purge', true);
    ArchivePeriod::factory()->create(['year' => 2020, 'month' => 3, 'archived_at' => now()->subYears(3)]);
    File::factory()->create([
        'period_year' => 2020, 'period_month' => 3, 'size' => 100, 'legal_hold' => true,
    ]);

    $this->artisan('doccum:purge-expired')->assertSuccessful();

    expect(File::withTrashed()->count())->toBe(1);
});

it('does nothing at all when no retention window is configured', function () {
    $this->travelTo('2026-06-01');
    config()->set('doccum.settings.retention.purge_after_years', null);
    config()->set('doccum.settings.retention.auto_purge', true);
    ArchivePeriod::factory()->create(['year' => 2020, 'month' => 3, 'archived_at' => now()->subYears(3)]);
    File::factory()->create(['period_year' => 2020, 'period_month' => 3, 'size' => 100]);

    $this->artisan('doccum:purge-expired')->assertSuccessful();

    expect(File::withTrashed()->count())->toBe(1);
});

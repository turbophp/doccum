<?php

declare(strict_types=1);

use App\Livewire\Admin\Periods;
use App\Models\ArchivePeriod;
use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// In the style of tests/Feature/AdminRolesTest.php.
beforeEach(function () {
    Storage::fake('documents');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->member = User::factory()->create();
    $this->member->assignRole('member');
});

function periodFile(int $year, ?int $month, array $attributes = []): File
{
    return File::factory()->create(
        ['period_year' => $year, 'period_month' => $month, 'size' => 100] + $attributes
    );
}

function archivedPeriod(int $year, ?int $month, array $attributes = []): ArchivePeriod
{
    return ArchivePeriod::factory()->create(
        ['year' => $year, 'month' => $month, 'archived_at' => now()->subYears(3)] + $attributes
    );
}

it('is 403 for a viewer without periods.manage', function () {
    $this->actingAs($this->member)
        ->get(route('admin.periods'))
        ->assertForbidden();
});

it('is 200 for a viewer with periods.manage', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.periods'))
        ->assertOk();
});

it('refuses the Livewire component itself to a viewer without periods.manage', function () {
    Livewire::actingAs($this->member)
        ->test(Periods::class)
        ->assertForbidden();
});

it('closes a period that has ended through the admin periods page', function () {
    $this->travelTo('2026-06-01');
    periodFile(2026, 3);

    Livewire::actingAs($this->admin)
        ->test(Periods::class)
        ->set('closeYear', '2026')
        ->set('closeMonth', '3')
        ->call('closePeriod')
        ->assertHasNoErrors();

    $period = ArchivePeriod::where('year', 2026)->where('month', 3)->first();

    expect($period)->not->toBeNull()
        ->and($period->isArchived())->toBeTrue();
});

it('surfaces a not-yet-ended period as an error rather than throwing', function () {
    $this->travelTo('2026-06-15');
    periodFile(2026, 6);

    Livewire::actingAs($this->admin)
        ->test(Periods::class)
        ->set('closeYear', '2026')
        ->set('closeMonth', '6')
        ->call('closePeriod')
        ->assertHasErrors('closePeriod');

    expect(ArchivePeriod::where('year', 2026)->where('month', 6)->exists())->toBeFalse();
});

it('refuses to purge a period when purgeable is false even though the request asks for it', function () {
    config()->set('doccum.settings.retention.purge_after_years', 1);
    $period = archivedPeriod(2020, 3);
    // A legal hold is the blocker: the period IS archived and past its
    // retention window, but plan()->purgeable must still be false.
    periodFile(2020, 3, ['legal_hold' => true]);

    Livewire::actingAs($this->admin)
        ->test(Periods::class)
        ->set("purgeConfirmation.{$period->id}", '2020-03')
        ->call('purgePeriod', $period->id)
        ->assertHasErrors("purge.{$period->id}");

    expect(File::withTrashed()->where('period_year', 2020)->where('period_month', 3)->count())->toBe(1)
        ->and($period->fresh()->purged_at)->toBeNull();
});

it('refuses to purge a period when the confirmation text does not exactly equal the label', function () {
    config()->set('doccum.settings.retention.purge_after_years', 1);
    $period = archivedPeriod(2020, 3);
    periodFile(2020, 3);

    Livewire::actingAs($this->admin)
        ->test(Periods::class)
        // Close, but not exact: missing the leading zero on the month.
        ->set("purgeConfirmation.{$period->id}", '2020-3')
        ->call('purgePeriod', $period->id)
        ->assertHasErrors("purge.{$period->id}");

    expect(File::withTrashed()->where('period_year', 2020)->where('period_month', 3)->count())->toBe(1)
        ->and($period->fresh()->purged_at)->toBeNull();
});

it('purges a purgeable period when the exact label is typed', function () {
    config()->set('doccum.settings.retention.purge_after_years', 1);
    $period = archivedPeriod(2020, 3);
    periodFile(2020, 3);

    Livewire::actingAs($this->admin)
        ->test(Periods::class)
        ->set("purgeConfirmation.{$period->id}", '2020-03')
        ->call('purgePeriod', $period->id)
        ->assertHasNoErrors();

    expect(File::withTrashed()->where('period_year', 2020)->where('period_month', 3)->count())->toBe(0)
        ->and($period->fresh()->purged_at)->not->toBeNull();
});

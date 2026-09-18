<?php

declare(strict_types=1);

use App\Actions\Files\SetLegalHold;
use App\Enums\AccessLevel;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use App\Services\PeriodPurger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->dir = Directory::factory()->create();
    $this->file = File::factory()->for($this->dir, 'directory')->create();
});

function grantDirectoryView(Directory $dir, User $user, AccessLevel $level = AccessLevel::View): void
{
    DirectoryGrant::create([
        'directory_id' => $dir->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $level,
    ]);
}

it('places and lifts a legal hold', function () {
    expect($this->file->legal_hold)->toBeFalse();

    $held = app(SetLegalHold::class)->handle($this->file, true);
    expect($held->legal_hold)->toBeTrue()
        ->and($this->file->refresh()->legal_hold)->toBeTrue();

    $lifted = app(SetLegalHold::class)->handle($held, false);
    expect($lifted->legal_hold)->toBeFalse();
});

it('refuses a caller with periods.manage but no directory access', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('periods.manage');

    expect($user->can('legalHold', $this->file))->toBeFalse();
});

it('refuses a caller with directory access but no periods.manage', function () {
    $user = User::factory()->create();
    grantDirectoryView($this->dir, $user);

    expect($user->can('legalHold', $this->file))->toBeFalse();
});

it('allows a caller with both periods.manage and directory access', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('periods.manage');
    grantDirectoryView($this->dir, $user);

    expect($user->can('legalHold', $this->file))->toBeTrue();
});

it('makes PeriodPurger::plan name the held file as a blocker end to end', function () {
    $this->travelTo('2026-06-01');
    config()->set('doccum.settings.retention.purge_after_years', 1);

    ArchivePeriod::factory()->create([
        'year' => 2020, 'month' => 3, 'archived_at' => now()->subYears(3),
    ]);

    $file = File::factory()->for($this->dir, 'directory')->create([
        'period_year' => 2020, 'period_month' => 3,
    ]);

    $user = User::factory()->create();
    $user->givePermissionTo('periods.manage');
    grantDirectoryView($this->dir, $user);

    expect($user->can('legalHold', $file))->toBeTrue();

    app(SetLegalHold::class)->handle($file, true);

    $plan = app(PeriodPurger::class)->plan(2020, 3);

    expect($plan->purgeable)->toBeFalse()
        ->and(implode(' ', $plan->blockers))->toContain('legal hold');
});

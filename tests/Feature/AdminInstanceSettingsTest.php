<?php

declare(strict_types=1);

use App\Livewire\Admin\InstanceSettings;
use App\Models\ArchivePeriod;
use App\Models\User;
use App\Services\PeriodPurger;
use App\Services\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

// In the style of tests/Feature/AdminPeriodsTest.php.
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->member = User::factory()->create();
    $this->member->assignRole('member');
});

it('is 403 for a viewer without users.manage', function () {
    $this->actingAs($this->member)
        ->get(route('admin.settings'))
        ->assertForbidden();
});

it('is 200 for a viewer with users.manage', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.settings'))
        ->assertOk();
});

it('refuses the Livewire component itself to a viewer without users.manage', function () {
    Livewire::actingAs($this->member)
        ->test(InstanceSettings::class)
        ->assertForbidden();
});

it('round-trips the instance name through the Settings service', function () {
    Livewire::actingAs($this->admin)
        ->test(InstanceSettings::class)
        ->set('instanceName', 'Acme Docs')
        ->call('saveInstanceName')
        ->assertHasNoErrors();

    expect(app(Settings::class)->get('instance.name'))->toBe('Acme Docs');
});

it('refuses a blank instance name and leaves the stored value untouched', function () {
    app(Settings::class)->set('instance.name', 'Acme Docs');

    Livewire::actingAs($this->admin)
        ->test(InstanceSettings::class)
        ->set('instanceName', '   ')
        ->call('saveInstanceName')
        ->assertHasErrors('instanceName');

    expect(app(Settings::class)->get('instance.name'))->toBe('Acme Docs');
});

// The doneWhen, verbatim: "toggling auth.public_signup flips /register
// between 200 and 404". Toggled through the component's own saveSignup(),
// never by writing the settings row directly, so this exercises the page
// and not merely EnsurePublicSignupEnabled's own middleware -- see this
// item's brief.
it('flips /register between 404 and 200 by toggling auth.public_signup through the page', function () {
    $this->get(route('register'))->assertNotFound();

    Livewire::actingAs($this->admin)
        ->test(InstanceSettings::class)
        ->set('publicSignup', true)
        ->call('saveSignup')
        ->assertHasNoErrors();

    // Livewire::actingAs() authenticates the underlying test session as the
    // admin, and register is a guest-only route -- checking it as a guest
    // (the actual audience for public signup) requires logging back out
    // first, or this would be testing what an authenticated admin sees on
    // /register, not what an anonymous visitor sees.
    Auth::logout();

    $this->get(route('register'))->assertOk();

    Livewire::actingAs($this->admin)
        ->test(InstanceSettings::class)
        ->set('publicSignup', false)
        ->call('saveSignup')
        ->assertHasNoErrors();

    Auth::logout();

    $this->get(route('register'))->assertNotFound();
});

// The retention wiring, item/admin-instance-settings' whole risk: retention
// used to live outside config('doccum.settings'), read by PeriodPurger
// through config() directly, so a settings-page write would have changed
// the stored row and nothing else. Asserted on plan()'s blockers, exactly
// as the brief requires, never on the stored `settings` row -- a row that
// changes while the blocker stays would be the same defect wearing a green
// test.
it("changes what PeriodPurger::plan() reports for a period by writing retention.purge_after_years through the page", function () {
    $period = ArchivePeriod::factory()->create([
        'year' => 2020,
        'month' => 3,
        'archived_at' => now()->subYears(3),
    ]);

    $before = app(PeriodPurger::class)->plan($period->year, $period->month);

    expect($before->purgeable)->toBeFalse()
        ->and($before->blockers)->toContain('No retention window is configured.');

    Livewire::actingAs($this->admin)
        ->test(InstanceSettings::class)
        ->set('retentionPurgeAfterYears', '1')
        ->call('saveRetention')
        ->assertHasNoErrors();

    $after = app(PeriodPurger::class)->plan($period->year, $period->month);

    expect($after->purgeable)->toBeTrue()
        ->and($after->blockers)->toBe([]);
});

it('refuses a non-numeric retention window and leaves the stored value untouched', function () {
    app(Settings::class)->set('retention.purge_after_years', 5);

    Livewire::actingAs($this->admin)
        ->test(InstanceSettings::class)
        ->set('retentionPurgeAfterYears', 'forever')
        ->call('saveRetention')
        ->assertHasErrors('retention');

    expect(app(Settings::class)->get('retention.purge_after_years'))->toBe(5);
});

it('accepts a blank retention window as "no retention configured"', function () {
    app(Settings::class)->set('retention.purge_after_years', 5);

    Livewire::actingAs($this->admin)
        ->test(InstanceSettings::class)
        ->set('retentionPurgeAfterYears', '')
        ->call('saveRetention')
        ->assertHasNoErrors();

    expect(app(Settings::class)->get('retention.purge_after_years'))->toBeNull();
});

it('round-trips retention.auto_purge alongside the retention window', function () {
    Livewire::actingAs($this->admin)
        ->test(InstanceSettings::class)
        ->set('retentionPurgeAfterYears', '2')
        ->set('autoPurge', true)
        ->call('saveRetention')
        ->assertHasNoErrors();

    expect(app(Settings::class)->get('retention.auto_purge'))->toBeTrue()
        ->and(app(Settings::class)->get('retention.purge_after_years'))->toBe(2);
});

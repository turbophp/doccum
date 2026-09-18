<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Services\Settings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Instance-wide operator configuration: instance name, public sign-up, and
 * document retention (spec §4 and §10, item/admin-instance-settings, issue
 * #21). "Limits" is named in the issue title but deliberately not built
 * here -- nothing in config/doccum.php reads an upload-size or quota value
 * today, and a field that writes a settings row nobody reads would be
 * exactly the defect this item exists to avoid. See the class's own note
 * on retention below for what that defect looks like when it DOES ship.
 *
 * Gated on `users.manage`, deliberately NOT a new `settings.manage`
 * permission -- the same call App\Livewire\Admin\Roles makes and for the
 * same reason, restated here in this class's own words rather than merely
 * pointed at: RolesAndPermissionsSeeder::PERMISSIONS has no such entry, and
 * adding one would mean a migration and a seeding story this item should
 * not carry. Instance configuration is administrative in the same sense
 * users and roles are, and it sits behind the same Settings entry point
 * (resources/views/layouts/app/topbar.blade.php) that Users and Roles do.
 *
 * Every ability check here is deliberately doubled up with routes/web.php's
 * own `can:users.manage` middleware on the `admin.settings` route, the same
 * reason App\Livewire\Admin\Roles, Users and Periods double their checks
 * (see any of those classes' own docblocks): a Livewire method call reaches
 * /livewire/update directly and does not re-run route middleware bound to a
 * DIFFERENT route than the one that rendered the page.
 *
 * Every write here goes through App\Services\Settings::set() -- never
 * Setting::create(), never a direct query, never a config() write. That is
 * this item's doneWhen clause one, and it is the whole reason retention
 * moved: `retention.purge_after_years` and `retention.auto_purge` used to
 * live OUTSIDE config('doccum.settings'), read by App\Services\PeriodPurger
 * and App\Console\Commands\PurgeExpired through config() directly. A
 * settings-page write to a `settings` row would have changed nothing --
 * both readers would have kept honouring the code default forever. Both
 * now read through Settings::get() (see PeriodPurger's own docblock), and
 * both retention values were moved under config('doccum.settings') so that
 * fallback resolves correctly for an unconfigured instance. See
 * tests/Feature/AdminInstanceSettingsTest.php's retention-wiring test, which
 * asserts on PeriodPurger::plan()'s blockers rather than on the stored row,
 * precisely so a regression of this shape cannot pass silently again.
 */
#[Layout('layouts::app')]
class InstanceSettings extends Component
{
    public string $instanceName = '';

    public bool $publicSignup = false;

    /**
     * String, not int|null, for the same reason
     * App\Livewire\Admin\Periods::$closeYear is a string: an empty text
     * input binds to '', and a typed int property cannot hold that.
     * Blank means "no retention window configured" -- App\Services\
     * PeriodPurger treats a null value as a blocker deliberately, so that
     * meaning has to stay expressible, not merely tolerated.
     */
    public string $retentionPurgeAfterYears = '';

    public bool $autoPurge = false;

    public function mount(): void
    {
        $this->authorize('users.manage');

        $settings = app(Settings::class);

        $this->instanceName = (string) $settings->get('instance.name', '');
        $this->publicSignup = (bool) $settings->get('auth.public_signup', false);

        $years = $settings->get('retention.purge_after_years');
        $this->retentionPurgeAfterYears = $years === null ? '' : (string) $years;

        $this->autoPurge = (bool) $settings->get('retention.auto_purge', false);
    }

    /**
     * A blank name is refused -- an instance with no name is not a valid
     * configuration, merely an unconfigured one, and the settings page is
     * exactly where that must be caught rather than left to whatever the
     * next reader of instance.name does with an empty string.
     */
    public function saveInstanceName(): void
    {
        // Method-level, alongside mount()'s -- see the class docblock.
        $this->authorize('users.manage');

        $this->resetErrorBag('instanceName');

        $name = trim($this->instanceName);

        if ($name === '') {
            $this->addError('instanceName', __('Enter an instance name.'));

            return;
        }

        app(Settings::class)->set('instance.name', $name, auth()->id());

        $this->instanceName = $name;
    }

    /**
     * Flips auth.public_signup. EnsurePublicSignupEnabled reads this exact
     * key to turn /register into a 404 -- see that middleware and this
     * item's doneWhen.
     */
    public function saveSignup(): void
    {
        // Method-level, alongside mount()'s -- see the class docblock.
        $this->authorize('users.manage');

        app(Settings::class)->set('auth.public_signup', $this->publicSignup, auth()->id());
    }

    /**
     * retention.purge_after_years is either blank (no retention window
     * configured -- PeriodPurger::blockers() treats that as a blocker,
     * deliberately, not as "keep nothing") or a positive integer number of
     * years. Never a 500 for a stray non-numeric value: refused through the
     * error bag instead, mirroring App\Livewire\Admin\Periods::closePeriod().
     */
    public function saveRetention(): void
    {
        // Method-level, alongside mount()'s -- see the class docblock.
        $this->authorize('users.manage');

        $this->resetErrorBag('retention');

        $years = null;

        if ($this->retentionPurgeAfterYears !== '') {
            if (! ctype_digit($this->retentionPurgeAfterYears) || (int) $this->retentionPurgeAfterYears < 1) {
                $this->addError('retention', __('Enter a positive number of years, or leave it blank for no retention window.'));

                return;
            }

            $years = (int) $this->retentionPurgeAfterYears;
        }

        $userId = auth()->id();
        $settings = app(Settings::class);

        $settings->set('retention.purge_after_years', $years, $userId);
        $settings->set('retention.auto_purge', $this->autoPurge, $userId);
    }

    public function render(): View
    {
        return view('livewire.admin.instance-settings');
    }
}

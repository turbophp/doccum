<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\ArchivePeriod;
use App\Services\PeriodCloser;
use App\Services\PeriodPurger;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Closing and purging archive periods (spec §9 and §10, item/admin-periods,
 * issue #20). Gated on `periods.manage`, the same permission
 * App\Services\PeriodPurger and App\Services\PeriodCloser already assume a
 * caller has checked -- this page and the two CLI commands
 * (App\Console\Commands\ClosePeriods, PurgePeriod) are the only callers, and
 * this one is the only one a request payload can reach.
 *
 * Every ability check here is deliberately doubled up with routes/web.php's
 * own `can:periods.manage` middleware on the `admin.periods` route, the same
 * reason App\Livewire\Admin\Roles and App\Livewire\Admin\Users double their
 * checks (see either class's own docblock): a Livewire method call reaches
 * /livewire/update directly and does not re-run route middleware bound to a
 * DIFFERENT route than the one that rendered the page.
 *
 * purgePeriod() carries the real risk this item exists for. The doneWhen
 * says the purge control is "disabled" while the plan is not purgeable --
 * that disabled attribute is a courtesy to the operator, not a guard, since
 * a Livewire method call reaches /livewire/update with whatever payload the
 * client sends regardless of what the button looked like. So purgePeriod()
 * re-runs PeriodPurger::plan() itself, against the database as it stands at
 * THIS request, and refuses on that fresh answer -- never on anything the
 * client sent and never on a plan computed for an earlier render(). It also
 * compares the typed confirmation against the fresh plan's label with a
 * strict (!==) comparison. Both refusals are .github/mutations.json entries.
 */
#[Layout('layouts::app')]
class Periods extends Component
{
    public string $closeYear = '';

    public string $closeMonth = '';

    /**
     * Pending confirmation text per archive period id, typed against
     * PurgePlan::label(). Keyed by ArchivePeriod::$id like
     * App\Livewire\Admin\Users::$roleChoice keys by user id.
     *
     * @var array<int, string>
     */
    public array $purgeConfirmation = [];

    public function mount(): void
    {
        $this->authorize('periods.manage');
    }

    /**
     * Closes a finished period, rolling up its file and byte counts.
     * PeriodCloser::close() throws InvalidArgumentException when the period
     * has not ended yet; that is surfaced through the error bag rather than
     * left to escape as a 500, mirroring how Roles::saveRole() surfaces
     * LastAdministratorMustRemain (see that class's docblock).
     */
    public function closePeriod(): void
    {
        // Method-level, alongside mount()'s -- see the class docblock.
        $this->authorize('periods.manage');

        $this->resetErrorBag('closePeriod');

        if ($this->closeYear === '' || ! ctype_digit($this->closeYear)) {
            $this->addError('closePeriod', __('Enter a year.'));

            return;
        }

        $month = null;

        if ($this->closeMonth !== '') {
            if (! ctype_digit($this->closeMonth) || (int) $this->closeMonth < 1 || (int) $this->closeMonth > 12) {
                $this->addError('closePeriod', __('Month must be between 1 and 12, or left blank for a whole year.'));

                return;
            }

            $month = (int) $this->closeMonth;
        }

        try {
            app(PeriodCloser::class)->close((int) $this->closeYear, $month);
        } catch (InvalidArgumentException $e) {
            $this->addError('closePeriod', $e->getMessage());

            return;
        }

        $this->reset(['closeYear', 'closeMonth']);
    }

    /**
     * Permanently deletes one period's rows and objects, after re-proving
     * -- server-side, against the current database -- that it may. See the
     * class docblock: neither guard below trusts the client.
     */
    public function purgePeriod(int $periodId): void
    {
        // Method-level, alongside mount()'s -- see the class docblock.
        $this->authorize('periods.manage');

        // find() + abort_if, not findOrFail() -- App\Livewire\Admin\Users::
        // changeRole() gives the same reasoning: findOrFail() throws
        // ModelNotFoundException, which a real request renders as a 404 but
        // which a Livewire component test never sees as one.
        $period = ArchivePeriod::query()->find($periodId);

        abort_if($period === null, 404);

        $this->resetErrorBag('purge.'.$periodId);

        // Re-run plan() now, against the database as it stands at THIS
        // request -- never the disabled attribute the client rendered, and
        // never a plan computed for an earlier render(). See the class
        // docblock.
        $plan = app(PeriodPurger::class)->plan($period->year, $period->month);

        if (! $plan->purgeable) {
            $this->addError('purge.'.$periodId, __('This period cannot be purged: :blockers', [
                'blockers' => implode(' ', $plan->blockers),
            ]));

            return;
        }

        $typed = $this->purgeConfirmation[$periodId] ?? '';

        // Strict, on purpose: the confirmation must equal the label
        // exactly, never merely be non-empty or loosely equal to it.
        if ($typed !== $plan->label()) {
            $this->addError('purge.'.$periodId, __('Type the exact period label, :label, to confirm.', [
                'label' => $plan->label(),
            ]));

            return;
        }

        app(PeriodPurger::class)->purge($period->year, $period->month);

        unset($this->purgeConfirmation[$periodId]);
    }

    public function render(): View
    {
        $periods = ArchivePeriod::query()
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();

        /** @var array<int, \App\Support\PurgePlan> $plans */
        $plans = [];

        foreach ($periods as $period) {
            if ($period->isArchived() && ! $period->isPurged()) {
                $plans[$period->id] = app(PeriodPurger::class)->plan($period->year, $period->month);
            }

            if (! array_key_exists($period->id, $this->purgeConfirmation)) {
                $this->purgeConfirmation[$period->id] = '';
            }
        }

        return view('livewire.admin.periods', [
            'periods' => $periods,
            'plans' => $plans,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PeriodPurger;
use Illuminate\Console\Command;

/**
 * Permanently deletes one period's rows and objects.
 *
 * A dry run unless given --force. Someone reaching for this command is
 * usually finding out what it would do, and the one command in doccum that
 * cannot be undone is the wrong place to make that the destructive default.
 */
class PurgePeriod extends Command
{
    protected $signature = 'doccum:purge-period {year : The period year} {month? : The period month, omitted for a whole year} {--force : Actually delete, rather than reporting what would go}';

    protected $description = 'Report, or with --force permanently delete, everything in one archived period';

    public function handle(PeriodPurger $purger): int
    {
        $year = (int) $this->argument('year');
        $month = $this->argument('month') === null ? null : (int) $this->argument('month');

        $plan = $purger->plan($year, $month);

        $this->components->twoColumnDetail('Period', $plan->label());
        $this->components->twoColumnDetail('Files', (string) $plan->fileCount);
        $this->components->twoColumnDetail('Bytes', (string) $plan->byteCount);

        // Printed before the --force check, so the reason is on screen
        // whether the operator was asking or telling.
        foreach ($plan->blockers as $blocker) {
            $this->line('  Blocked: '.$blocker);
        }

        if (! $this->option('force')) {
            $this->components->info('Dry run. Nothing was deleted. Pass --force to purge.');

            // MUTANT, NOT FOR MERGE (mutation for issue #340). The regression
            // checkPurgePeriodDryRunLeavesFileInPlace() exists to catch: a dry
            // run that deletes while still printing every word of its own
            // reassurance. Deletes directly rather than through PeriodPurger,
            // because the smoke's container configures no retention window, so
            // purge() would refuse and the command would exit non-zero -- which
            // would fail the check at the docker exec step instead of at the
            // row read-back, leaving the assertion under test unproven.
            \App\Models\File::withTrashed()
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->forceDelete();

            return self::SUCCESS;
        }

        if (! $plan->purgeable) {
            $this->components->error('Refused: this period cannot be purged.');

            return self::FAILURE;
        }

        $report = $purger->purge($year, $month);

        $this->components->info(sprintf(
            'Purged %s: %d file(s), %d version(s), %d object(s), %d byte(s).',
            $report->label(),
            $report->fileCount,
            $report->versionCount,
            $report->objectCount,
            $report->byteCount,
        ));

        return self::SUCCESS;
    }
}

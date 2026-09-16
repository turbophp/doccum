<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PeriodPurger;
use Illuminate\Console\Command;

/**
 * Purges every period that has aged out of the retention window.
 *
 * Reports and deletes nothing unless doccum.retention.auto_purge is on.
 * Scheduled deletion is opt-in because nobody is watching when it runs, and
 * an instance that was never configured for retention must not be the one
 * that starts deleting.
 */
class PurgeExpired extends Command
{
    protected $signature = 'doccum:purge-expired';

    protected $description = 'Purge every period past the retention window, if automatic purging is enabled';

    public function handle(PeriodPurger $purger): int
    {
        if (config('doccum.retention.purge_after_years') === null) {
            $this->components->info('No retention window is configured. Nothing to do.');

            return self::SUCCESS;
        }

        $expired = $purger->expired();

        if ($expired->isEmpty()) {
            $this->components->info('No periods are past the retention window.');

            return self::SUCCESS;
        }

        $auto = (bool) config('doccum.retention.auto_purge');

        foreach ($expired as $period) {
            $year = (int) $period->year;
            $month = $period->month === null ? null : (int) $period->month;

            $plan = $purger->plan($year, $month);

            if (! $plan->purgeable) {
                $this->line(sprintf('  Skipped %s: %s', $plan->label(), implode(' ', $plan->blockers)));

                continue;
            }

            if (! $auto) {
                $this->line(sprintf(
                    '  Candidate %s: %d file(s), %d byte(s).',
                    $plan->label(),
                    $plan->fileCount,
                    $plan->byteCount,
                ));

                continue;
            }

            $report = $purger->purge($year, $month);

            $this->line(sprintf(
                '  Purged %s: %d file(s), %d object(s), %d byte(s).',
                $report->label(),
                $report->fileCount,
                $report->objectCount,
                $report->byteCount,
            ));
        }

        $this->components->info($auto
            ? sprintf('Considered %d expired period(s).', $expired->count())
            : sprintf('Automatic purging is off. Reported %d candidate(s), deleted nothing.', $expired->count()));

        return self::SUCCESS;
    }
}

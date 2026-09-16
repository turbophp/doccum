<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ArchivePeriod;
use App\Services\PeriodCloser;
use Illuminate\Console\Command;

/**
 * Closes every period that has ended, recording what it holds.
 *
 * Safe to run on a schedule and safe to run twice: closing is idempotent and
 * never reopens a period that has already been purged.
 */
class ClosePeriods extends Command
{
    protected $signature = 'doccum:close-periods';

    protected $description = 'Close every period that has ended, recording its file and byte counts';

    public function handle(PeriodCloser $closer): int
    {
        $closed = $closer->closeFinished();

        if ($closed->isEmpty()) {
            $this->components->info('No periods to close.');

            return self::SUCCESS;
        }

        $this->table(
            ['Period', 'Files', 'Bytes'],
            $closed->map(fn (ArchivePeriod $period): array => [
                sprintf('%04d-%02d', $period->year, $period->month),
                (string) $period->file_count,
                (string) $period->byte_count,
            ])->all(),
        );

        $this->components->info(sprintf('Closed %d period(s).', $closed->count()));

        return self::SUCCESS;
    }
}

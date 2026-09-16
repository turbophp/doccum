<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ArchivePeriod;
use App\Models\File;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Rolls up a finished period: how many files it holds, and how many bytes.
 *
 * Trashed files are counted deliberately -- trash keeps its objects, so a
 * byte count that ignores them understates what purging would actually
 * reclaim, and that number is what an operator decides on. See spec §9.
 */
class PeriodCloser
{
    public function close(int $year, ?int $month = null): ArchivePeriod
    {
        $this->assertHasEnded($year, $month);

        $query = $this->filesQuery($year, $month);

        $fileCount = (clone $query)->count();
        $byteCount = (int) (clone $query)->sum('size');

        // updateOrCreate only touches the attributes it is given, so an
        // existing purged_at survives untouched: closing never reopens a
        // period that has already been purged.
        return ArchivePeriod::updateOrCreate(
            ['year' => $year, 'month' => $month],
            [
                'file_count' => $fileCount,
                'byte_count' => $byteCount,
                'archived_at' => now(),
            ],
        );
    }

    /** @return Collection<int, ArchivePeriod> */
    public function closeFinished(): Collection
    {
        return File::withTrashed()
            ->select('period_year', 'period_month')
            ->distinct()
            ->get()
            ->filter(fn (File $row): bool => $this->hasEnded((int) $row->period_year, (int) $row->period_month))
            ->map(fn (File $row): ArchivePeriod => $this->close((int) $row->period_year, (int) $row->period_month))
            ->values();
    }

    private function assertHasEnded(int $year, ?int $month): void
    {
        if (! $this->hasEnded($year, $month)) {
            throw new InvalidArgumentException(sprintf(
                'The period %04d-%s has not ended yet.',
                $year,
                $month === null ? '(year)' : sprintf('%02d', $month),
            ));
        }
    }

    private function hasEnded(int $year, ?int $month): bool
    {
        $end = $month === null
            ? CarbonImmutable::create($year, 12, 1)->endOfYear()
            : CarbonImmutable::create($year, $month, 1)->endOfMonth();

        return now()->greaterThan($end);
    }

    /** @return Builder<File> */
    private function filesQuery(int $year, ?int $month): Builder
    {
        $query = File::withTrashed()->where('period_year', $year);

        if ($month !== null) {
            $query->where('period_month', $month);
        }

        return $query;
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What a completed purge actually destroyed.
 *
 * Counted as the work happens rather than copied from the plan, so the
 * numbers an operator is shown are what went, not what was expected to go.
 */
final readonly class PurgeReport
{
    public function __construct(
        public int $year,
        public ?int $month,
        public int $fileCount,
        public int $byteCount,
        public int $versionCount,
        public int $objectCount,
    ) {}

    public function label(): string
    {
        return $this->month === null
            ? sprintf('%04d', $this->year)
            : sprintf('%04d-%02d', $this->year, $this->month);
    }
}

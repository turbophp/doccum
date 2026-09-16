<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What purging a period would destroy, and every reason it may not.
 *
 * Returned by {@see \App\Services\PeriodPurger::plan()} without writing
 * anything, so "what would this do" is answerable without doing it -- which
 * is the only honest way to offer an irreversible operation. See spec §9.
 */
final readonly class PurgePlan
{
    /** @param  array<int, string>  $blockers */
    public function __construct(
        public int $year,
        public ?int $month,
        public bool $purgeable,
        public int $fileCount,
        public int $byteCount,
        public array $blockers = [],
    ) {}

    public function label(): string
    {
        return $this->month === null
            ? sprintf('%04d', $this->year)
            : sprintf('%04d-%02d', $this->year, $this->month);
    }
}

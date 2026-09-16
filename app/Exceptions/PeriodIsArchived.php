<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an action would write into a period that has been archived.
 * An archived period is read-only: see spec §9.
 */
class PeriodIsArchived extends RuntimeException
{
    public static function forPeriod(int $year, int $month): self
    {
        return new self(sprintf(
            'The period %04d-%02d is archived and accepts no new files or versions.',
            $year,
            $month,
        ));
    }
}

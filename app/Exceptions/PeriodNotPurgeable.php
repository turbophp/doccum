<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a purge was refused, carrying every reason it was refused.
 *
 * Purging is the one irreversible operation in doccum, so a refusal has to
 * say what would have to change rather than simply failing: an operator who
 * is told "no" and not told why will reach for the database. See spec §9.
 */
class PeriodNotPurgeable extends RuntimeException
{
    /** @param  array<int, string>  $blockers */
    public function __construct(string $message, public readonly array $blockers = [])
    {
        parent::__construct($message);
    }

    /** @param  array<int, string>  $blockers */
    public static function because(int $year, ?int $month, array $blockers): self
    {
        return new self(
            sprintf(
                'The period %s cannot be purged: %s',
                self::label($year, $month),
                implode(' ', $blockers),
            ),
            $blockers,
        );
    }

    public static function label(int $year, ?int $month): string
    {
        return $month === null ? sprintf('%04d', $year) : sprintf('%04d-%02d', $year, $month);
    }
}

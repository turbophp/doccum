<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where one directory archive has got to.
 *
 * Every archive ends in exactly one terminal state, Ready or Failed, so a row
 * is never left indistinguishable from one still being worked on -- the same
 * rule ExtractionStatus follows, and for the same reason: a poller with no
 * terminal state polls for ever.
 */
enum ArchiveStatus: string
{
    case Pending = 'pending';
    case Building = 'building';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Ready || $this === self::Failed;
    }
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The outcome of trying to get text out of one file version.
 *
 * A version leaves the extraction job with exactly one of these -- including
 * Unsupported and Failed -- so a missing row is never ambiguous with "not
 * yet tried" and nothing is silently unsearchable. See spec §7.
 */
enum ExtractionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
    case Unsupported = 'unsupported';
}

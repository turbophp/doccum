<?php

declare(strict_types=1);

namespace App\Actions\Files;

use App\Exceptions\FileIsUnderLegalHold;
use App\Exceptions\PeriodIsArchived;
use App\Models\ArchivePeriod;
use App\Models\File;

/**
 * Soft deletes a file. Objects are deliberately left in place: trash is
 * recoverable, and the bytes are only reclaimed when a period is purged.
 *
 * This action never authorises -- the caller does, through FilePolicy.
 */
class TrashFile
{
    public function handle(File $file): void
    {
        if ($file->legal_hold) {
            throw FileIsUnderLegalHold::forFile($file);
        }

        if (ArchivePeriod::isArchivedFor((int) $file->period_year, (int) $file->period_month)) {
            throw PeriodIsArchived::forPeriod((int) $file->period_year, (int) $file->period_month);
        }

        $file->delete();
    }
}

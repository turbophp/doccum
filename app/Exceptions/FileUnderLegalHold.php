<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a file under legal hold would otherwise be permanently
 * deleted.
 *
 * PeriodPurger already refuses a whole period that contains a held file;
 * this is the same rule enforced at the single-file door that a manual purge
 * opens. A hold exists precisely to block irreversible deletion, and letting
 * one file be purged on its own would be exactly the side door it exists to
 * close. See spec §9.
 */
class FileUnderLegalHold extends RuntimeException
{
    public static function for(int $fileId, string $name): self
    {
        return new self(sprintf('File %d ("%s") is under legal hold and cannot be purged.', $fileId, $name));
    }
}

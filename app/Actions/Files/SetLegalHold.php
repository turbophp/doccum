<?php

declare(strict_types=1);

namespace App\Actions\Files;

use App\Models\File;

/**
 * Places or lifts a legal hold on a file.
 *
 * This action never authorises -- the caller does, through
 * FilePolicy::legalHold(), which gates on the periods.manage permission and
 * on directory access. See spec §9.
 */
class SetLegalHold
{
    public function handle(File $file, bool $hold): File
    {
        $file->legal_hold = $hold;
        $file->save();

        return $file->refresh();
    }
}

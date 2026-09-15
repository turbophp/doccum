<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class RuntimeConfigUnreadable extends RuntimeException
{
    public static function at(string $path): self
    {
        return new self(
            "The runtime configuration at {$path} exists but could not be read. "
            .'This usually means APP_KEY has changed since it was written. '
            .'Restore the original APP_KEY, or remove the file to reconfigure from scratch.'
        );
    }
}

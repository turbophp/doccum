<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\RuntimeConfig;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * The documented way out of a lockout: clears the runtime config file so the
 * installer runs again on next boot.
 *
 * Deliberately never calls RuntimeConfig::read() -- a corrupt file that
 * cannot be decrypted is precisely the situation this command exists to
 * recover from, and read() would throw before forget() ever ran.
 */
class ConfigReset extends Command
{
    use ConfirmableTrait;

    protected $signature = 'doccum:config:reset {--force : Skip the confirmation prompt}';

    protected $description = 'Delete the runtime configuration file, even if it cannot be read';

    public function handle(): int
    {
        if (! $this->confirmToProceed('This will remove the runtime database configuration.')) {
            return self::FAILURE;
        }

        RuntimeConfig::forget();

        $this->components->info('Runtime configuration cleared.');

        return self::SUCCESS;
    }
}

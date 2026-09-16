<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Restarts the workers supervised alongside the web process.
 *
 * A supervised worker holds a database connection built from the configuration
 * that existed when it started. When the installer repoints the database, the
 * workers keep talking to the bootstrap SQLite -- they do not crash, they
 * quietly process against the wrong database, which is worse than failing.
 *
 * Only meaningful in the single-container image. Under compose the workers are
 * separate containers that override the command and never run supervisor, so
 * this reports that it could not restart them and the caller says so.
 */
final class SupervisedProcesses
{
    private const CONFIG = '/etc/supervisor/conf.d/doccum.conf';

    private const PROGRAMS = ['worker', 'worker-ingest', 'scheduler'];

    public static function available(): bool
    {
        return is_file(self::CONFIG) && self::binary() !== null;
    }

    /** Returns true when a restart was actually performed. */
    public static function restartWorkers(): bool
    {
        $binary = self::binary();

        if ($binary === null || ! is_file(self::CONFIG)) {
            return false;
        }

        try {
            $process = new Process([$binary, '-c', self::CONFIG, 'restart', ...self::PROGRAMS]);
            $process->setTimeout(30);
            $process->run();

            return $process->isSuccessful();
        } catch (ExceptionInterface) {
            // Never let a failed restart fail an installation. The caller
            // tells the operator to restart the container instead.
            return false;
        }
    }

    private static function binary(): ?string
    {
        foreach (['/usr/bin/supervisorctl', '/usr/local/bin/supervisorctl'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Exceptions\RuntimeConfigUnreadable;
use App\Services\Settings;
use App\Support\EmbeddedStorage;
use App\Support\RuntimeConfig;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Applies the installer's runtime overrides before anything resolves a
 * database connection or a disk.
 *
 * Registered FIRST in bootstrap/providers.php. Configuration is already loaded
 * by the time any provider registers, and connections are resolved lazily, so
 * register() is early enough -- and is the last moment that is still early
 * enough. See spec §10a.
 */
class RuntimeConfigServiceProvider extends ServiceProvider
{
    private static ?string $error = null;

    public static function hasError(): bool
    {
        return self::$error !== null;
    }

    public static function error(): ?string
    {
        return self::$error;
    }

    public function register(): void
    {
        $this->applyOverrides();
    }

    public function applyOverrides(): void
    {
        self::$error = null;

        try {
            $config = RuntimeConfig::read();
        } catch (RuntimeConfigUnreadable $e) {
            // Never fall back to environment defaults here: an empty database
            // would look like a fresh install and invite a reinstall over live
            // data. Task 6's guard turns this into a readable page.
            self::$error = $e->getMessage();

            return;
        }

        if ($config === []) {
            return;
        }

        $this->applyDatabase($config['database'] ?? []);
    }

    /** @param  array<string, mixed>  $database */
    private function applyDatabase(array $database): void
    {
        if ($database === []) {
            return;
        }

        $connection = (string) ($database['connection'] ?? config('database.default'));
        config()->set('database.default', $connection);

        foreach (['host', 'port', 'database', 'username', 'password', 'charset'] as $key) {
            if (array_key_exists($key, $database)) {
                config()->set("database.connections.{$connection}.{$key}", $database[$key]);
            }
        }
    }

    /**
     * Storage configuration comes from the settings table, not the file.
     *
     * Applied in boot() rather than register() because it needs the database --
     * which is safe, since nothing resolves a disk during boot. The file stays
     * limited to the one thing that genuinely cannot be read from the database:
     * how to reach the database.
     */
    public function boot(): void
    {
        if (self::hasError() || ! Schema::hasTable('settings')) {
            return;
        }

        // Increasing precedence: embedded credentials first, so an operator
        // who configures nothing still gets working storage, then any
        // storage.* setting -- a remote provider always overrides the
        // embedded default.
        $this->applyEmbeddedStorage();

        $settings = $this->app->make(Settings::class);

        foreach (['endpoint', 'key', 'secret', 'bucket', 'region'] as $key) {
            $value = $settings->get("storage.{$key}");

            if ($value !== null) {
                config()->set("filesystems.disks.documents.{$key}", $value);
            }
        }
    }

    private function applyEmbeddedStorage(): void
    {
        $credentials = EmbeddedStorage::credentials();

        if ($credentials === null) {
            return;
        }

        config()->set('filesystems.disks.documents.key', $credentials['key']);
        config()->set('filesystems.disks.documents.secret', $credentials['secret']);
        config()->set('filesystems.disks.documents.endpoint', config('doccum.storage.endpoint'));
    }
}

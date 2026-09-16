<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\StorageProvider;
use App\Exceptions\RuntimeConfigUnreadable;
use App\Services\Settings;
use App\Support\EmbeddedStorage;
use App\Support\RuntimeConfig;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

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
    /**
     * Whether the settings table can be consulted at all.
     *
     * Schema::hasTable() does not merely return false without a database -- it
     * throws. That happens during an image build, where `composer
     * dump-autoload` runs package:discover with no database in the image at
     * all, and would fail the build. It also happens when a container starts
     * before its database is reachable. In both cases the right answer is to
     * leave environment configuration standing rather than to crash.
     */
    private function settingsAreReadable(): bool
    {
        try {
            return Schema::hasTable('settings');
        } catch (Throwable) {
            return false;
        }
    }

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
        if (self::hasError()) {
            return;
        }

        // Embedded credentials come from a file, not the database, so they are
        // safe to apply even when no database exists.
        $this->applyEmbeddedStorage();

        if (! $this->settingsAreReadable()) {
            return;
        }

        // Increasing precedence: embedded credentials first, so an operator
        // who configures nothing still gets working storage, then the chosen
        // provider's preset, then any explicit storage.* setting -- a preset
        // is a default, not a cage, so an explicit value always wins.
        $settings = $this->app->make(Settings::class);

        $this->applyStorageProviderPreset($settings);

        foreach (['endpoint', 'key', 'secret', 'bucket', 'region'] as $key) {
            $value = $settings->get("storage.{$key}");

            if ($value !== null) {
                config()->set("filesystems.disks.documents.{$key}", $value);
            }
        }
    }

    /**
     * Derives endpoint, region, and addressing style from the operator's
     * chosen storage.provider preset. Left entirely alone when no provider is
     * stored (a fresh install with the embedded default) or the stored value
     * does not name a known provider -- tryFrom(), never from(), so a stray
     * value is ignored rather than crashing boot().
     */
    private function applyStorageProviderPreset(Settings $settings): void
    {
        $providerValue = $settings->get('storage.provider');

        if ($providerValue === null) {
            return;
        }

        $provider = StorageProvider::tryFrom((string) $providerValue);

        if ($provider === null) {
            return;
        }

        $account = $settings->get('storage.account');
        $region = $settings->get('storage.region');

        $endpoint = $provider->endpointFor(
            $account !== null ? (string) $account : null,
            $region !== null ? (string) $region : null,
        );

        if ($endpoint !== null) {
            config()->set('filesystems.disks.documents.endpoint', $endpoint);
        }

        $defaultRegion = $provider->defaultRegion();

        if ($defaultRegion !== null) {
            config()->set('filesystems.disks.documents.region', $defaultRegion);
        }

        config()->set('filesystems.disks.documents.use_path_style_endpoint', $provider->usesPathStyle());
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

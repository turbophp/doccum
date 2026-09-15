<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Operator-editable settings, layered over config defaults.
 *
 * config/doccum.php ships the defaults in code; rows in `settings` override
 * them at runtime. A fresh install with an empty table is fully functional,
 * which the zero-configuration boot depends on. See spec §4.
 */
class Settings
{
    private const CACHE_KEY = 'doccum.settings';

    public function __construct(
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $stored = $this->all();

        if (array_key_exists($key, $stored)) {
            return $stored[$key];
        }

        return $this->config->get("doccum.settings.{$key}", $default);
    }

    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $userId],
        );

        $this->flush();
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->cache->rememberForever(
            self::CACHE_KEY,
            fn (): array => Setting::query()->pluck('value', 'key')->all(),
        );
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}

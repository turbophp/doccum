<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Crypt;

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

    /**
     * Marks a stored value as an encrypted secret rather than a hardcoded list
     * of key names -- so decryption is driven by what is actually stored.
     */
    private const SECRET_MARKER = '__encrypted';

    public function __construct(
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $stored = $this->all();

        if (array_key_exists($key, $stored)) {
            $value = $stored[$key];

            if (is_array($value) && array_key_exists(self::SECRET_MARKER, $value)) {
                return Crypt::decryptString($value[self::SECRET_MARKER]);
            }

            return $value;
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

    public function setSecret(string $key, string $value, ?int $userId = null): void
    {
        $this->set($key, [self::SECRET_MARKER => Crypt::encryptString($value)], $userId);
    }

    public function isSecret(string $key): bool
    {
        $stored = $this->all();

        return is_array($stored[$key] ?? null) && array_key_exists(self::SECRET_MARKER, $stored[$key]);
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

<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Env;

/**
 * Resolves TRUSTED_PROXIES into whatever Middleware::trustProxies()'s `at`
 * parameter expects. Pulled out of bootstrap/app.php so the parsing itself --
 * unset, "*", an explicit empty string, or a comma-separated list -- is
 * unit-testable without booting an application for every case. See
 * bootstrap/app.php for why the default (a private/loopback range list, not
 * "*" and not an empty list) is what it is.
 */
class TrustedProxies
{
    /**
     * The default when TRUSTED_PROXIES is not set at all: the address ranges
     * a reverse proxy actually connects from in every deployment this image
     * ships for -- a sibling container on the same Docker bridge network (the
     * default bridge is 172.17.0.0/16, a Compose network is typically
     * 172.18.0.0/16 upward, both inside 172.16.0.0/12), a proxy on the host
     * reaching the published port over loopback, or a proxy given a fixed
     * address on a user-defined bridge (commonly 10.x or 192.168.x).
     *
     * @var list<string>
     */
    private const DEFAULT_PROXIES = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.1/8',
        '::1',
    ];

    /**
     * TRUSTED_PROXIES as the process was actually started with, already
     * resolved into what trustProxies() wants.
     *
     * Read through Illuminate\Support\Env rather than the env() helper on
     * purpose, and NOT through config(). Larastan's noEnvCallsOutsideOfConfig
     * rule exists because env() returns null once the config is cached -- but
     * that reasoning does not reach this call. bootstrap/app.php configures
     * middleware while the application is still being constructed, before any
     * configuration has been loaded, so there is no cached config to consult
     * and config('...') here would resolve to null whether or not the
     * operator set anything. The raw environment is the only source that
     * exists at this point, and config caching never touches it.
     */
    public static function fromEnvironment(): array|string
    {
        $raw = Env::get('TRUSTED_PROXIES');

        return self::resolve(is_string($raw) ? trim($raw) : null);
    }

    /**
     * @return list<string>|string
     */
    public static function resolve(?string $trustedProxiesEnv): array|string
    {
        return match (true) {
            $trustedProxiesEnv === null => self::DEFAULT_PROXIES,
            $trustedProxiesEnv === '*' => '*',
            default => array_values(array_filter(
                array_map('trim', explode(',', $trustedProxiesEnv)),
            )),
        };
    }
}

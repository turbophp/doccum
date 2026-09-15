<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reads the root credentials generated on first boot by
 * docker/entrypoint.d/48-doccum-storage.sh into /data/minio.env.
 *
 * A plain class, like RuntimeConfig: it is consumed by
 * RuntimeConfigServiceProvider::boot(), which runs before the container is
 * useful and cannot depend on anything heavier. The file is never written by
 * PHP -- only ever read -- so this class has no write side.
 */
final class EmbeddedStorage
{
    public static function isActive(): bool
    {
        return self::credentials() !== null;
    }

    /** @return array{key: string, secret: string}|null */
    public static function credentials(): ?array
    {
        $path = self::path();

        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if (! is_string($raw)) {
            return null;
        }

        $values = self::parse($raw);
        $key = $values['MINIO_ROOT_USER'] ?? null;
        $secret = $values['MINIO_ROOT_PASSWORD'] ?? null;

        if ($key === null || $secret === null || $key === '' || $secret === '') {
            return null;
        }

        return ['key' => $key, 'secret' => $secret];
    }

    private static function path(): string
    {
        return (string) config('doccum.storage.embedded_env');
    }

    /** @return array<string, string> */
    private static function parse(string $raw): array
    {
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $line, 2), 2, null);

            if ($key === null || $value === null) {
                continue;
            }

            $values[trim($key)] = trim($value);
        }

        return $values;
    }
}

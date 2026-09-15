<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\RuntimeConfigUnreadable;
use Illuminate\Encryption\Encrypter;
use Throwable;

/**
 * Encrypted runtime overrides on the data volume.
 *
 * A plain class rather than a container service: the provider that consumes it
 * runs in register(), before the container is useful, and the credentials it
 * carries are the ones the container would otherwise need to boot.
 *
 * Encryption uses APP_KEY, which lives on the same volume. That is defence in
 * depth, not a security boundary -- it keeps credentials out of backups,
 * support bundles and logs, but does not defend against someone who can read
 * the volume. See spec §10a.
 */
final class RuntimeConfig
{
    private const VERSION = 1;

    public static function path(): string
    {
        return (string) config('doccum.runtime_config_path');
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    /** @return array<string, mixed> */
    public static function read(): array
    {
        if (! self::exists()) {
            return [];
        }

        $raw = @file_get_contents(self::path());
        $envelope = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($envelope) || ! isset($envelope['payload'])) {
            throw RuntimeConfigUnreadable::at(self::path());
        }

        try {
            $decoded = self::encrypter()->decrypt($envelope['payload']);
        } catch (Throwable) {
            // Deliberately not falling back to an empty array: a configured
            // instance must never look like a fresh one.
            throw RuntimeConfigUnreadable::at(self::path());
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @param  array<string, mixed>  $payload */
    public static function write(array $payload): void
    {
        $envelope = json_encode([
            'doccum' => self::VERSION,
            'payload' => self::encrypter()->encrypt($payload),
        ], JSON_PRETTY_PRINT);

        $path = self::path();
        @mkdir(dirname($path), 0755, true);

        // Written to a temporary file then renamed: a half-written config is
        // indistinguishable from a corrupt one, and corrupt means locked out.
        $temp = $path.'.'.getmypid().'.tmp';
        file_put_contents($temp, $envelope);
        chmod($temp, 0600);
        rename($temp, $path);
    }

    public static function forget(): void
    {
        if (self::exists()) {
            @unlink(self::path());
        }
    }

    private static function encrypter(): Encrypter
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return new Encrypter($key, (string) config('app.cipher', 'AES-256-CBC'));
    }
}

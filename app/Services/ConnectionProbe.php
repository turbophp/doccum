<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final readonly class ProbeResult
{
    public function __construct(public bool $ok, public ?string $message = null) {}

    public static function ok(): self
    {
        return new self(true);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}

/**
 * Probes a database or storage configuration before it is trusted, so a typo
 * fails at the installer form instead of at first use, when the operator has
 * gone. See spec §10a.
 *
 * The deliberate exception to "nothing outside App\Services\DocumentStorage
 * resolves a disk": probing storage reachability is this service's entire
 * job.
 */
class ConnectionProbe
{
    /** @param  array<string, mixed>  $config */
    public function database(array $config): ProbeResult
    {
        $name = 'doccum_probe_'.Str::random(8);
        $connection = (string) ($config['connection'] ?? 'sqlite');

        $settings = array_merge(
            config("database.connections.{$connection}", []),
            array_intersect_key($config, array_flip(['host', 'port', 'database', 'username', 'password'])),
            ['driver' => $connection],
        );

        config()->set("database.connections.{$name}", $settings);

        try {
            DB::connection($name)->getPdo();

            return ProbeResult::ok();
        } catch (Throwable $e) {
            return ProbeResult::failed($this->scrub($e->getMessage(), $config));
        } finally {
            DB::purge($name);
            config()->set("database.connections.{$name}", null);
        }
    }

    /** @param  array<string, mixed>  $config */
    public function storage(array $config): ProbeResult
    {
        $disk = (string) ($config['disk'] ?? 'doccum_probe');

        if (! isset($config['disk'])) {
            config()->set("filesystems.disks.{$disk}", array_merge(
                config('filesystems.disks.documents', []),
                array_intersect_key($config, array_flip(['endpoint', 'key', 'secret', 'bucket', 'region'])),
            ));
        }

        $probeKey = '.doccum-probe-'.Str::random(12);

        try {
            // A real write and a real delete: read-only credentials and a
            // missing bucket both pass a naive existence check.
            Storage::disk($disk)->put($probeKey, 'ok');
            Storage::disk($disk)->delete($probeKey);

            return ProbeResult::ok();
        } catch (Throwable $e) {
            return ProbeResult::failed($this->scrub($e->getMessage(), $config));
        }
    }

    /**
     * Driver exceptions routinely quote the whole DSN. Never hand a credential
     * back to the browser or to a log.
     *
     * @param  array<string, mixed>  $config
     */
    private function scrub(string $message, array $config): string
    {
        foreach (['password', 'secret', 'key'] as $field) {
            $value = $config[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $message = str_replace($value, '[redacted]', $message);
            }
        }

        return Str::limit($message, 300);
    }
}

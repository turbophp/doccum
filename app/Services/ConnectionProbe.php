<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Redact;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
 * Whether a database the installer is about to attach to already belongs to a
 * doccum instance.
 *
 * `Populated` and `KeyMismatch` are both "not fresh" -- they differ only in
 * whether this APP_KEY can prove it. Anything that isn't provably fresh or
 * provably ours is treated as KeyMismatch: a users table that exists for some
 * other reason is exactly the case attaching must refuse, not tiptoe around.
 */
enum InstanceState
{
    case Fresh;
    case Populated;
    case KeyMismatch;
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
        return $this->withTemporaryConnection($config, function (string $name) {
            DB::connection($name)->getPdo();

            return ProbeResult::ok();
        }, fn (Throwable $e) => ProbeResult::failed($this->scrub($e->getMessage(), $config)));
    }

    /**
     * Determines whether the database at $config already belongs to a doccum
     * instance, entirely through a disposable connection of its own.
     *
     * Never switches database.default and never migrates: both would act on
     * the database before its identity is known, which is exactly the mistake
     * this method exists to prevent the caller from making. See plan Task 7.
     *
     * @param  array<string, mixed>  $config
     */
    public function inspect(array $config): InstanceState
    {
        return $this->withTemporaryConnection($config, function (string $name) {
            if (! Schema::connection($name)->hasTable('users')) {
                return InstanceState::Fresh;
            }

            if (! DB::connection($name)->table('users')->exists()) {
                return InstanceState::Fresh;
            }

            if (! Schema::connection($name)->hasTable('settings')) {
                return InstanceState::KeyMismatch;
            }

            $keyCheck = DB::connection($name)->table('settings')
                ->where('key', 'instance.key_check')
                ->value('value');

            if ($keyCheck === null) {
                return InstanceState::KeyMismatch;
            }

            try {
                Crypt::decryptString((string) json_decode((string) $keyCheck, true));

                return InstanceState::Populated;
            } catch (Throwable) {
                return InstanceState::KeyMismatch;
            }
        }, fn (Throwable $e): InstanceState => InstanceState::KeyMismatch);
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
     * Builds a throwaway named connection from $config, hands its name to
     * $callback, and always purges it afterward -- whether $callback returns
     * normally or the connection attempt itself throws.
     *
     * Shared by database() and inspect() so both act through a connection that
     * is never database.default and is never left registered: exactly the
     * property that makes it safe to probe or inspect an unproven database
     * mid-request.
     *
     * @template T
     *
     * @param  array<string, mixed>  $config
     * @param  callable(string): T  $callback
     * @param  callable(Throwable): T  $onFailure
     * @return T
     */
    private function withTemporaryConnection(array $config, callable $callback, callable $onFailure): mixed
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
            return $callback($name);
        } catch (Throwable $e) {
            return $onFailure($e);
        } finally {
            DB::purge($name);
            config()->set("database.connections.{$name}", null);
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
        $message = Redact::secrets($message, [
            $config['password'] ?? null,
            $config['secret'] ?? null,
            $config['key'] ?? null,
        ]);

        return Str::limit($message, 300);
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use PHPUnit\Framework\Assert;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The single place an external command is executed.
 *
 * Every extraction strategy shells out through here rather than constructing
 * its own `Symfony\Component\Process\Process`, so a timeout or a missing
 * binary is handled once instead of in every strategy, and the decision logic
 * built on top of it can be tested on a machine with none of the real
 * binaries installed. Bound as a singleton (see DoccumServiceProvider) so
 * `fake()` state is visible to whatever the container hands out next within
 * the same test.
 */
class ProcessRunner
{
    /** @var array<string, array{output?: string, error?: string, exitCode?: int}>|null */
    private ?array $fakeResponses = null;

    /** @var list<list<string>> */
    private array $ranCommands = [];

    /**
     * @param  list<string>  $command
     */
    public function run(array $command, ?int $timeout = null): ProcessResult
    {
        $this->ranCommands[] = $command;

        if ($this->fakeResponses !== null) {
            return $this->runFaked($command);
        }

        try {
            $process = new Process($command);
            $process->setTimeout($timeout ?? 60);
            $process->run();

            return new ProcessResult(
                ok: $process->isSuccessful(),
                output: $process->getOutput(),
                error: $process->getErrorOutput(),
                exitCode: $process->getExitCode() ?? 1,
            );
        } catch (Throwable $e) {
            // A missing binary, a refused fork, a timeout -- none of it may
            // ever crash the caller. Extraction must be able to mark a
            // document unsupported instead of taking down the worker.
            return new ProcessResult(
                ok: false,
                output: '',
                error: $e->getMessage(),
                exitCode: 1,
            );
        }
    }

    public function available(string $binary): bool
    {
        if ($this->fakeResponses !== null) {
            return array_key_exists($binary, $this->fakeResponses);
        }

        return (new ExecutableFinder)->find($binary) !== null;
    }

    /**
     * @param  array<string, array{output?: string, error?: string, exitCode?: int}>  $responses
     */
    public static function fake(array $responses): void
    {
        $instance = app(static::class);

        if (! $instance instanceof self) {
            throw new RuntimeException('ProcessRunner is not bound to itself in the container.');
        }

        $instance->fakeResponses = $responses;
        $instance->ranCommands = [];
    }

    public function assertRan(string $binaryFragment): void
    {
        $ran = collect($this->ranCommands)->contains(
            fn (array $command): bool => str_contains($command[0] ?? '', $binaryFragment),
        );

        Assert::assertTrue($ran, "No command matching [{$binaryFragment}] was run.");
    }

    /**
     * @param  list<string>  $command
     */
    private function runFaked(array $command): ProcessResult
    {
        $binary = $command[0] ?? '';
        $response = $this->fakeResponses[$binary] ?? null;

        if ($response === null) {
            return new ProcessResult(
                ok: false,
                output: '',
                error: "no fake response configured for [{$binary}]",
                exitCode: 1,
            );
        }

        $exitCode = $response['exitCode'] ?? 0;

        return new ProcessResult(
            ok: $exitCode === 0,
            output: $response['output'] ?? '',
            error: $response['error'] ?? '',
            exitCode: $exitCode,
        );
    }
}

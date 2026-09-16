<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The outcome of one command run through {@see ProcessRunner}.
 *
 * A missing binary, a timeout, and a non-zero exit all end up here rather
 * than as a thrown exception -- extraction strategies decide what a failure
 * means (unsupported vs. failed); the runner just reports what happened.
 */
final readonly class ProcessResult
{
    public function __construct(
        public bool $ok,
        public string $output,
        public string $error,
        public int $exitCode,
    ) {}
}

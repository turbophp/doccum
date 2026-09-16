<?php

declare(strict_types=1);

namespace App\Extraction;

/**
 * One strategy for turning a file on disk into text.
 *
 * Each strategy declares which MIME types it handles; {@see ExtractorChain}
 * picks the first that claims a given type. Nothing outside this contract
 * shells out to an external tool directly -- a strategy that needs one goes
 * through App\Support\ProcessRunner, so the decision logic stays testable
 * without the binary installed.
 */
interface TextExtractor
{
    public function supports(string $mime): bool;

    public function extract(string $path, string $mime): ExtractionResult;

    public function name(): string;
}

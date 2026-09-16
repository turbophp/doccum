<?php

declare(strict_types=1);

namespace App\Extraction\Strategies;

use App\Extraction\ExtractionResult;
use App\Extraction\TextExtractor;

/**
 * The fallback for any MIME type no other strategy claims.
 *
 * A format doccum cannot read yet is not an error: the extraction job must
 * still leave a `file_texts` row behind, just one that says "unsupported"
 * rather than pretending the document has no content. See spec §7.
 */
final class UnsupportedExtractor implements TextExtractor
{
    public function supports(string $mime): bool
    {
        return true;
    }

    public function extract(string $path, string $mime): ExtractionResult
    {
        return ExtractionResult::unsupported();
    }

    public function name(): string
    {
        return 'unsupported';
    }
}

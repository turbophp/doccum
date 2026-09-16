<?php

declare(strict_types=1);

namespace App\Extraction\Strategies;

use App\Extraction\ExtractionResult;
use App\Extraction\TextExtractor;

/**
 * Reads a file that is already text: no external tool needed.
 */
final class PlainTextExtractor implements TextExtractor
{
    private const MIME_TYPES = [
        'text/plain',
        'text/csv',
        'text/markdown',
    ];

    public function supports(string $mime): bool
    {
        return in_array($mime, self::MIME_TYPES, true);
    }

    public function extract(string $path, string $mime): ExtractionResult
    {
        $raw = @file_get_contents($path);

        if ($raw === false) {
            return ExtractionResult::failed("could not read [{$path}]", $this->name());
        }

        return ExtractionResult::done($this->coerceToUtf8($raw), $this->name());
    }

    public function name(): string
    {
        return 'plain';
    }

    /**
     * Scanned exports and legacy tools routinely produce bad bytes; storing
     * them raw would break JSON encoding later, in the search index, so
     * invalid sequences are dropped rather than kept.
     */
    private function coerceToUtf8(string $raw): string
    {
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $raw);

        return $converted !== false ? $converted : mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
    }
}

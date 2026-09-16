<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Enums\ExtractionStatus;

/**
 * What one strategy found -- or didn't -- for one file.
 *
 * A value object rather than an exception: a document a strategy cannot
 * read is not a failure of the extraction job, so "no text" is a normal,
 * typed outcome instead of a thrown error. See spec §7.
 */
final readonly class ExtractionResult
{
    public function __construct(
        public ExtractionStatus $status,
        public ?string $text,
        public ?string $extractor,
        public ?string $error,
    ) {}

    public static function done(string $text, string $extractor): self
    {
        return new self(ExtractionStatus::Done, $text, $extractor, null);
    }

    public static function failed(string $error, ?string $extractor = null): self
    {
        return new self(ExtractionStatus::Failed, null, $extractor, $error);
    }

    public static function unsupported(): self
    {
        return new self(ExtractionStatus::Unsupported, null, null, null);
    }
}

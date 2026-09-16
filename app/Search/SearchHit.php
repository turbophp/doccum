<?php

declare(strict_types=1);

namespace App\Search;

use App\Models\SearchDocument;

/** One search result: what matched, and how well. */
final readonly class SearchHit
{
    public function __construct(
        public string $subjectType,
        public int $subjectId,
        public string $title,
        public string $snippet,
        public float $score,
        public ?int $directoryId = null,
    ) {}

    public static function fromDocument(SearchDocument $document, float $score, string $snippet = ''): self
    {
        return new self(
            subjectType: $document->subject_type,
            subjectId: $document->subject_id,
            title: $document->title,
            snippet: $snippet !== '' ? $snippet : mb_substr((string) $document->body, 0, 200),
            score: $score,
            directoryId: $document->directory_id,
        );
    }
}

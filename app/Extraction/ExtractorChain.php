<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Extraction\Strategies\OfficeXmlExtractor;
use App\Extraction\Strategies\PlainTextExtractor;
use App\Extraction\Strategies\UnsupportedExtractor;

/**
 * Picks the strategy for a MIME type.
 *
 * An unknown type resolves to {@see UnsupportedExtractor} rather than
 * throwing: a format doccum cannot read yet is not an error. See spec §7.
 */
final class ExtractorChain
{
    /** @var list<TextExtractor> */
    private readonly array $strategies;

    public function __construct(
        PlainTextExtractor $plainText,
        OfficeXmlExtractor $officeXml,
        private readonly UnsupportedExtractor $fallback,
    ) {
        $this->strategies = [$plainText, $officeXml];
    }

    public function for(string $mime): TextExtractor
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($mime)) {
                return $strategy;
            }
        }

        return $this->fallback;
    }
}

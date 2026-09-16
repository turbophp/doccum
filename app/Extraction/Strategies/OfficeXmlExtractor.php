<?php

declare(strict_types=1);

namespace App\Extraction\Strategies;

use App\Extraction\ExtractionResult;
use App\Extraction\TextExtractor;
use ZipArchive;

/**
 * Reads modern Office formats by unzipping them and stripping their XML.
 *
 * A .docx/.xlsx/.pptx is a zip archive of XML, so no LibreOffice is needed
 * to get its text out -- which is why WITH_OFFICE stays off by default in
 * the image. See spec §7.
 */
final class OfficeXmlExtractor implements TextExtractor
{
    private const WORD = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const SHEET = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const SLIDES = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    private const SINGLE_ENTRY = [
        self::WORD => 'word/document.xml',
        self::SHEET => 'xl/sharedStrings.xml',
    ];

    public function supports(string $mime): bool
    {
        return in_array($mime, [self::WORD, self::SHEET, self::SLIDES], true);
    }

    public function extract(string $path, string $mime): ExtractionResult
    {
        $zip = new ZipArchive;
        $opened = $zip->open($path);

        if ($opened !== true) {
            return ExtractionResult::failed("could not open [{$path}] as a zip archive (code {$opened})", $this->name());
        }

        try {
            $xml = $this->readXml($zip, $mime);
        } finally {
            $zip->close();
        }

        if (trim($xml) === '') {
            return ExtractionResult::failed('no readable content found in the office document', $this->name());
        }

        return ExtractionResult::done($this->stripToText($xml), $this->name());
    }

    public function name(): string
    {
        return 'office';
    }

    private function readXml(ZipArchive $zip, string $mime): string
    {
        if ($mime === self::SLIDES) {
            return $this->readSlides($zip);
        }

        $entry = self::SINGLE_ENTRY[$mime] ?? null;

        if ($entry === null) {
            return '';
        }

        $contents = $zip->getFromName($entry);

        return $contents !== false ? $contents : '';
    }

    /** Slides are numbered files rather than one document, so every one is read and joined in order. */
    private function readSlides(ZipArchive $zip): string
    {
        $slides = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name !== false && preg_match('#^ppt/slides/slide\d+\.xml$#', $name) === 1) {
                $contents = $zip->getFromName($name);
                $slides[$name] = $contents !== false ? $contents : '';
            }
        }

        ksort($slides, SORT_NATURAL);

        return implode("\n", $slides);
    }

    private function stripToText(string $xml): string
    {
        $decoded = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', $decoded));
    }
}

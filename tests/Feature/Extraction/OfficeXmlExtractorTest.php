<?php

declare(strict_types=1);

use App\Enums\ExtractionStatus;
use App\Extraction\Strategies\OfficeXmlExtractor;

/** Build a minimal but genuine .docx: a zip containing word/document.xml. */
function makeDocx(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'doccum').'.docx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml',
        '<?xml version="1.0"?><w:document xmlns:w="x"><w:body><w:p><w:r><w:t>'
        .$body.'</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();

    return $path;
}

it('extracts text from a docx', function () {
    $path = makeDocx('Quarterly report contents');

    $result = app(OfficeXmlExtractor::class)->extract($path, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    expect($result->status)->toBe(ExtractionStatus::Done)
        ->and($result->text)->toContain('Quarterly report contents')
        ->and($result->text)->not->toContain('<w:t>');

    @unlink($path);
});

it('fails cleanly on a corrupt archive', function () {
    $path = tempnam(sys_get_temp_dir(), 'doccum').'.docx';
    file_put_contents($path, 'this is not a zip');

    $result = app(OfficeXmlExtractor::class)->extract($path, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    expect($result->status)->toBe(ExtractionStatus::Failed)
        ->and($result->error)->not->toBeEmpty();

    @unlink($path);
});

it('claims the modern office types but not the legacy ones', function () {
    $e = app(OfficeXmlExtractor::class);

    expect($e->supports('application/vnd.openxmlformats-officedocument.wordprocessingml.document'))->toBeTrue()
        ->and($e->supports('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))->toBeTrue()
        ->and($e->supports('application/msword'))->toBeFalse();
});

/** A minimal but genuine .xlsx: shared strings are where cell text actually lives. */
function makeXlsx(string ...$strings): string
{
    $path = tempnam(sys_get_temp_dir(), 'doccum').'.xlsx';
    $items = implode('', array_map(fn (string $s): string => "<si><t>{$s}</t></si>", $strings));
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/sharedStrings.xml',
        '<?xml version="1.0"?><sst xmlns="x" count="'.count($strings).'">'.$items.'</sst>');
    $zip->close();

    return $path;
}

/** A minimal .pptx: one slide, text in drawing-markup runs. */
function makePptx(string ...$slides): string
{
    $path = tempnam(sys_get_temp_dir(), 'doccum').'.pptx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($slides as $i => $body) {
        $zip->addFromString('ppt/slides/slide'.($i + 1).'.xml',
            '<?xml version="1.0"?><p:sld xmlns:p="x" xmlns:a="y"><p:cSld><p:spTree>'
            .'<p:sp><p:txBody><a:p><a:r><a:t>'.$body.'</a:t></a:r></a:p></p:txBody></p:sp>'
            .'</p:spTree></p:cSld></p:sld>');
    }
    $zip->close();

    return $path;
}

it('extracts cell text from an xlsx', function () {
    $path = makeXlsx('Invoice total', '1500.75');

    $result = app(OfficeXmlExtractor::class)->extract(
        $path, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    expect($result->status)->toBe(ExtractionStatus::Done)
        ->and($result->text)->toContain('Invoice total', '1500.75')
        ->and($result->text)->not->toContain('<si>');

    @unlink($path);
});

it('extracts text from every slide of a pptx', function () {
    // Every slide, not just the first: a deck's content is spread across them.
    $path = makePptx('Opening remarks', 'Revenue by region', 'Closing summary');

    $result = app(OfficeXmlExtractor::class)->extract(
        $path, 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
    );

    expect($result->status)->toBe(ExtractionStatus::Done)
        ->and($result->text)->toContain('Opening remarks', 'Revenue by region', 'Closing summary')
        ->and($result->text)->not->toContain('<a:t>');

    @unlink($path);
});

it('fails cleanly on an office file missing its expected part', function () {
    $path = tempnam(sys_get_temp_dir(), 'doccum').'.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('unrelated.txt', 'nothing useful here');
    $zip->close();

    $result = app(OfficeXmlExtractor::class)->extract(
        $path, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    expect($result->status)->toBeIn([ExtractionStatus::Failed, ExtractionStatus::Unsupported]);

    @unlink($path);
});

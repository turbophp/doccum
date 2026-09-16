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

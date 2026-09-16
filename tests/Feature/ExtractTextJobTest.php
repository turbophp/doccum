<?php

declare(strict_types=1);

use App\Actions\Files\StoreFileVersion;
use App\Enums\ExtractionStatus;
use App\Jobs\ExtractText;
use App\Models\Directory;
use App\Models\FileText;
use App\Models\User;
use App\Support\ProcessRunner;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    $this->user = User::factory()->create();
    $this->dir = Directory::factory()->create();
});

function storeText(string $name, string $contents, string $mime = 'text/plain')
{
    $path = tempnam(sys_get_temp_dir(), 'doccum');
    file_put_contents($path, $contents);

    return app(StoreFileVersion::class)->handle(test()->user, test()->dir, $path, $name, $mime);
}

it('is dispatched to the ingest queue on upload', function () {
    Queue::fake();

    storeText('a.txt', 'hello');

    Queue::assertPushedOn('ingest', ExtractText::class);
});

it('records extracted text against the version', function () {
    $file = storeText('a.txt', 'the quick brown fox');

    ExtractText::dispatchSync($file->currentVersion);

    $text = FileText::where('file_version_id', $file->currentVersion->id)->firstOrFail();

    expect($text->status)->toBe(ExtractionStatus::Done)
        ->and($text->text)->toContain('quick brown fox')
        ->and($text->chars)->toBeGreaterThan(0);
});

it('records a failure instead of leaving nothing behind', function () {
    ProcessRunner::fake(['pdftotext' => ['exitCode' => 1, 'error' => 'damaged']]);
    $file = storeText('a.pdf', '%PDF-1.4 broken', 'application/pdf');

    ExtractText::dispatchSync($file->currentVersion);

    // A version with no row at all is indistinguishable from one not yet
    // processed, so a failure must be written down.
    $text = FileText::where('file_version_id', $file->currentVersion->id)->firstOrFail();
    expect($text->status)->toBeIn([ExtractionStatus::Failed, ExtractionStatus::Unsupported]);
});

it('marks an unreadable type unsupported', function () {
    $file = storeText('a.rom', 'binary junk', 'application/x-nintendo-rom');

    ExtractText::dispatchSync($file->currentVersion);

    expect(FileText::first()->status)->toBe(ExtractionStatus::Unsupported);
});

it('is idempotent', function () {
    $file = storeText('a.txt', 'hello');

    ExtractText::dispatchSync($file->currentVersion);
    ExtractText::dispatchSync($file->currentVersion);

    expect(FileText::count())->toBe(1);
});

it('re-queues failures on demand', function () {
    Queue::fake();
    $file = storeText('a.txt', 'hello');
    FileText::create([
        'file_version_id' => $file->currentVersion->id,
        'status' => ExtractionStatus::Failed,
        'error' => 'transient',
    ]);

    $this->artisan('doccum:extract:retry')->assertSuccessful();

    Queue::assertPushed(ExtractText::class);
});

it('leaves a successful extraction alone when retrying', function () {
    Queue::fake();
    $file = storeText('a.txt', 'hello');
    FileText::create([
        'file_version_id' => $file->currentVersion->id,
        'status' => ExtractionStatus::Done,
        'text' => 'hello', 'chars' => 5,
    ]);

    // storeText() itself already dispatched one ExtractText job (the upload
    // path this file's first test covers); re-fake here to isolate what the
    // retry command dispatches on its own, which is what this test is about.
    Queue::fake();

    $this->artisan('doccum:extract:retry')->assertSuccessful();

    Queue::assertNotPushed(ExtractText::class);
});

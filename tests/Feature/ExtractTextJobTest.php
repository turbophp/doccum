<?php

declare(strict_types=1);

use App\Actions\Files\StoreFileVersion;
use App\Enums\ExtractionStatus;
use App\Jobs\ExtractText;
use App\Models\Directory;
use App\Models\FileText;
use App\Models\FileVersion;
use App\Models\User;
use App\Services\DocumentStorage;
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

it('gives a retry real spacing instead of retrying instantly', function () {
    // No backoff meant three tries could burn in well under a second while
    // MinIO was still coming up -- see the CI run in the job's docblock.
    // This is what makes that impossible: a genuine gap before either retry.
    $job = new ExtractText(FileVersion::factory()->make());

    expect($job->backoff)->not->toBeEmpty();

    foreach ($job->backoff as $seconds) {
        expect($seconds)->toBeGreaterThan(0);
    }
});

it('retries a storage read that fails once, rather than settling as failed', function () {
    Queue::fake();
    $file = storeText('a.txt', 'hello');
    $version = $file->currentVersion;

    $recovered = tempnam(sys_get_temp_dir(), 'doccum-retry');
    file_put_contents($recovered, 'hello world');

    $this->mock(DocumentStorage::class, function ($mock) use ($version, $recovered) {
        $mock->shouldReceive('downloadToTemp')
            ->once()
            ->with($version)
            ->andThrow(new RuntimeException('Unable to read object from storage.'));

        $mock->shouldReceive('downloadToTemp')
            ->once()
            ->with($version)
            ->andReturn($recovered);
    });

    // Attempt 1: the object store is not yet serving the object. The
    // exception must reach the caller uncaught -- catching it here and
    // writing a Failed row would spend the whole retry budget on what is
    // only a transient read, exactly the bug this test guards against.
    expect(fn () => ExtractText::dispatchSync($version))->toThrow(RuntimeException::class);
    expect(FileText::where('file_version_id', $version->id)->exists())->toBeFalse();

    // Attempt 2 -- what the run after $backoff's delay looks like: storage
    // now serves the object and extraction settles normally.
    ExtractText::dispatchSync($version);

    $text = FileText::where('file_version_id', $version->id)->firstOrFail();
    expect($text->status)->toBe(ExtractionStatus::Done);
});

it('settles a genuinely unextractable document as failed on the first attempt', function () {
    // A damaged document is not a transient read: the extractor chain
    // reports Failed as a value and never throws, so this must settle on
    // attempt one and never touch the retry budget $backoff now spaces out.
    ProcessRunner::fake(['pdftotext' => ['exitCode' => 1, 'error' => 'damaged']]);
    $file = storeText('a.pdf', '%PDF-1.4 broken', 'application/pdf');
    $version = $file->currentVersion;

    // No wrapping expectation: an uncaught exception here fails the test on
    // its own, which is exactly what proves nothing here is retrying.
    ExtractText::dispatchSync($version);

    $text = FileText::where('file_version_id', $version->id)->firstOrFail();
    expect($text->status)->toBe(ExtractionStatus::Failed);
});

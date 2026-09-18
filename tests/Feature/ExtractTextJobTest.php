<?php

declare(strict_types=1);

use App\Actions\Files\StoreFileVersion;
use App\Enums\ExtractionStatus;
use App\Extraction\ExtractorChain;
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
    // Queue::fake() so storeText()'s own dispatch does not run extraction and
    // leave a settled row behind -- handle() returns early on one.
    Queue::fake();
    $file = storeText('a.txt', 'hello');
    $version = $file->currentVersion;

    $recovered = tempnam(sys_get_temp_dir(), 'doccum-retry');
    file_put_contents($recovered, 'hello world');

    // handle() is called directly with an explicit double, rather than
    // through dispatchSync() and a container binding. Two earlier attempts
    // at this went through the bus and reported only "exception not thrown",
    // which says nothing about WHICH of the queue fake, the container
    // binding, or the double was responsible. Calling the method under test
    // with its collaborator passed in has none of those between the
    // assertion and the behaviour.
    $job = new ExtractText($version);
    $chain = app(ExtractorChain::class);

    // Attempt 1: the object store is not serving the object. The exception
    // must leave handle() uncaught -- swallowing it and writing a Failed row
    // would spend the whole retry budget on a transient read, which is the
    // bug this test guards against.
    $failing = Mockery::mock(DocumentStorage::class);
    $failing->shouldReceive('downloadToTemp')
        ->once()
        ->andThrow(new RuntimeException('Unable to read object from storage.'));

    expect(fn () => $job->handle($failing, $chain))->toThrow(RuntimeException::class);
    expect(FileText::where('file_version_id', $version->id)->exists())->toBeFalse();

    // Attempt 2 -- what the run after $backoff's delay looks like: storage
    // serves the object and extraction settles normally.
    $serving = Mockery::mock(DocumentStorage::class);
    $serving->shouldReceive('downloadToTemp')
        ->once()
        ->andReturn($recovered);

    $job->handle($serving, $chain);

    $text = FileText::where('file_version_id', $version->id)->firstOrFail();
    expect($text->status)->toBe(ExtractionStatus::Done);
});

/**
 * issue #153, half two. A missing object is permanent, so this must not
 * spend $tries/$backoff retrying it -- and CLAUDE.md's own trap applies
 * directly: the test queue is synchronous, so a synchronous queue never
 * retries ANYTHING, fixed or not, and the file_texts ROW this settles into
 * is identical either way (see below). What differs is whether the job's
 * own uncaught exception is allowed to escape dispatchSync() at all, which
 * is the mechanism this asserts rather than the outcome.
 *
 * Storage::fake('documents') keeps this disk's real config.throw => true
 * (Illuminate\Support\Facades\Storage::buildDiskConfiguration() only
 * defaults it when the real disk does not set it), so the missing object
 * comes out of readStream() as Flysystem's UnableToReadFile here too, the
 * same signal production sees -- not a fake-disk artifact.
 */
it('treats a missing object as terminal instead of spending its retry budget on it', function () {
    $file = storeText('a.txt', 'hello');
    $version = $file->currentVersion;

    // The upload above already dispatched ExtractText on the (still real)
    // sync queue and settled a Done row while the object still existed.
    // Clearing it, rather than suppressing that dispatch with Queue::fake(),
    // is deliberate: Queue::fake() would also swallow the dispatchSync()
    // below, and it is dispatchSync()'s real behaviour under the actual
    // sync queue that this test needs to observe.
    FileText::where('file_version_id', $version->id)->delete();

    // The row still points at bytes that are gone -- the operator-restore-
    // without-objects scenario issue #153 reaches through the queue rather
    // than a download route (#134).
    Storage::disk('documents')->delete($version->object_key);

    // No wrapping expectation, on purpose: with the fix, handle() catches
    // ObjectMissingFromStorage and calls $this->fail($e) itself, which
    // marks the job failed and deletes it from the queue from INSIDE
    // handle(), then handle() returns normally -- dispatchSync() must not
    // throw here.
    //
    // Revert the fix and this line fails: the (bare, pre-#153)
    // RuntimeException escapes handle() uncaught, and
    // Illuminate\Queue\SyncQueue::handleException() -- which runs
    // regardless of $tries, because a sync queue has no persisted attempt
    // count to check it against -- marks the SAME job failed on that same
    // first call and then rethrows it, so dispatchSync() throws. That is
    // the only place a synchronous queue can show this: the file_texts row
    // below is written by the identical failed() call either way, so
    // asserting on IT would pass whether or not the fix is present, exactly
    // the unfalsifiable shape CLAUDE.md and decision/0062 warn about.
    ExtractText::dispatchSync($version);

    $text = FileText::where('file_version_id', $version->id)->firstOrFail();
    expect($text->status)->toBe(ExtractionStatus::Failed)
        ->and($text->error)->toBe("Object [{$version->object_key}] was not found in storage.");
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

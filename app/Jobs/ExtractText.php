<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ExtractionStatus;
use App\Exceptions\ObjectMissingFromStorage;
use App\Extraction\ExtractionResult;
use App\Extraction\ExtractorChain;
use App\Models\FileText;
use App\Models\FileVersion;
use App\Services\DocumentStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Turns one file version's bytes into a `file_texts` row.
 *
 * Always ends in exactly one outcome -- Done, Unsupported, or Failed -- so a
 * version is never left with no row at all, which would be indistinguishable
 * from one not yet processed and is how a document becomes silently
 * unsearchable with no way to notice. See spec §7.
 */
class ExtractText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Real spacing between attempts, in seconds.
     *
     * Without this, a retry is immediate: all three tries can burn in well
     * under a second, so `$tries = 3` bought nothing at all against any
     * condition that takes longer than that to clear.
     *
     * Only a storage-layer exception is ever retried. The extractor chain
     * does not throw for a document it cannot read -- it returns a Failed or
     * Unsupported ExtractionResult as a value, settled on the first attempt
     * -- so this spacing never delays a genuine verdict.
     *
     * What it does NOT claim is a diagnosis. The CI failure that prompted
     * this
     * (https://github.com/turbophp/doccum/actions/runs/35223806664) was put
     * down to MinIO not yet serving, but the smoke had already uploaded the
     * file through the web UI, which writes to MinIO, so MinIO was demonstrably
     * serving before the read failed. The real cause is still unknown; it
     * passed on re-run. Spacing retries is right on its own merits, and the
     * smoke now prints file_texts.error so the next occurrence is diagnosed
     * from the exception rather than from a guess.
     *
     * @var list<int>
     */
    public array $backoff = [5, 15];

    public int $timeout;

    public function __construct(public readonly FileVersion $version)
    {
        $this->onQueue('ingest');
        $this->timeout = (int) config('doccum.extraction.timeout_seconds');
    }

    public function handle(DocumentStorage $storage, ExtractorChain $chain): void
    {
        // A settled outcome (Done or Unsupported) is the honest final
        // answer already; re-running would waste work for no new
        // information. A Failed or Pending row is reprocessed, which is
        // what lets doccum:extract:retry re-queue without re-uploading.
        $existing = FileText::query()->where('file_version_id', $this->version->id)->first();

        if ($existing !== null && in_array($existing->status, [ExtractionStatus::Done, ExtractionStatus::Unsupported], true)) {
            return;
        }

        try {
            $path = $storage->downloadToTemp($this->version);
        } catch (ObjectMissingFromStorage $e) {
            // Permanent, not transient: $tries/$backoff above exist for a
            // read that might succeed on the next attempt, and bytes that
            // are gone from storage are never coming back. Burning all
            // three tries and 20 seconds of backoff against that delays the
            // only useful outcome -- an operator seeing why -- and does it
            // three times for nothing.
            //
            // $this->fail($e) is InteractsWithQueue's own escape hatch for
            // exactly this: it marks the job failed and deletes it from the
            // queue immediately, which happens INSIDE this call, bypassing
            // $tries entirely rather than racing it. It also invokes
            // failed() below with $e right here, so file_texts.error ends
            // up with ObjectMissingFromStorage's own message -- "Object
            // [...] was not found in storage." -- rather than whatever a
            // caught-and-rethrown generic exception would have said.
            // Returning afterwards, rather than letting $e propagate, is
            // what keeps the exception from ALSO going through the normal
            // release-and-retry handling a queue driver gives an uncaught
            // one -- fail() already settled this permanently.
            $this->fail($e);

            return;
        }

        try {
            $result = $chain->for($this->version->mime)->extract($path, $this->version->mime);
        } finally {
            @unlink($path);
        }

        $this->store($result);
    }

    /**
     * Even a worker giving up after every retry must not leave the version
     * with no row at all.
     */
    public function failed(?Throwable $exception): void
    {
        FileText::updateOrCreate(
            ['file_version_id' => $this->version->id],
            [
                'status' => ExtractionStatus::Failed,
                'error' => $exception?->getMessage() ?? 'extraction failed',
            ],
        );

        ReindexSearchDocument::dispatch($this->version->file);
    }

    private function store(ExtractionResult $result): void
    {
        FileText::updateOrCreate(
            ['file_version_id' => $this->version->id],
            [
                'status' => $result->status,
                'extractor' => $result->extractor,
                'text' => $result->text,
                'chars' => $result->text !== null ? mb_strlen($result->text) : 0,
                'error' => $result->error,
            ],
        );

        // The file's body is only as current as its text, so the moment an
        // outcome lands -- Done, Unsupported, or a text change on retry --
        // the file's search projection needs rebuilding, not merely on
        // upload before any text existed.
        ReindexSearchDocument::dispatch($this->version->file);
    }
}

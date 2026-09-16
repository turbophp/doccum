<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ExtractionStatus;
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

        $path = $storage->downloadToTemp($this->version);

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

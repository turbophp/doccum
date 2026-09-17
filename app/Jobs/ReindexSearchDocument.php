<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Directory;
use App\Models\File;
use App\Models\Property;
use App\Services\SearchIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Rebuilds one subject's row in `search_documents`, off the request.
 *
 * Runs on the queue in production and inline in tests (the `sync` driver),
 * so a name change, a property edit or a directory move is reflected without
 * making the request that caused it pay for re-flattening text and
 * properties. See spec §8.
 */
class ReindexSearchDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly Directory|File|Property $subject) {}

    public function handle(SearchIndexer $indexer): void
    {
        // A trashed subject must never come back. SearchProjectionObserver
        // ::deleted() forgets the projection inline, but this job can be
        // DISPATCHED before a trash and RUN after it -- the dispatch from the
        // upload itself, or an ExtractText still in flight -- and index() does
        // an updateOrCreate, so it would rebuild the row for a soft-deleted
        // file and republish it to the index. The file then answers searches
        // again, which is the exact thing that observer's docblock says an
        // index must not become: a way to keep reading what was just deleted.
        //
        // Invisible to the suite by construction: QUEUE_CONNECTION is sync
        // there, so every dispatch runs inline and in order, before the trash
        // ever happens. CLAUDE.md says it plainly -- the test queue is
        // synchronous and production is not. The container smoke caught it on
        // main, not the 617 tests.
        if (method_exists($this->subject, 'trashed') && $this->subject->trashed()) {
            $indexer->forget($this->subject);

            return;
        }

        $indexer->index($this->subject);
    }
}

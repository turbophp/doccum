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
        $indexer->index($this->subject);
    }
}

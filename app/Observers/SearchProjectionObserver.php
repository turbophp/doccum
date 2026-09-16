<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\ReindexSearchDocument;
use App\Models\Directory;
use App\Models\File;
use App\Models\Property;
use App\Services\SearchIndexer;

/**
 * Keeps `search_documents` current for Directory, File and Property.
 *
 * `deleted` fires for a soft delete as much as a force delete, so a trashed
 * file is forgotten immediately rather than on some later sweep -- a search
 * index is otherwise a way to keep reading what was just deleted. `saved`
 * queues a rebuild rather than doing it inline, since flattening extracted
 * text and every property can be real work. See spec §8.
 */
class SearchProjectionObserver
{
    public function saved(Directory|File|Property $subject): void
    {
        ReindexSearchDocument::dispatch($subject);

        // A property's value is also flattened into its subject's body, so a
        // subject's document is stale the moment one of its properties
        // changes unless that subject is reindexed too.
        if ($subject instanceof Property) {
            $this->reindexPropertySubject($subject);
        }
    }

    public function deleted(Directory|File|Property $subject): void
    {
        app(SearchIndexer::class)->forget($subject);

        if ($subject instanceof Property) {
            $this->reindexPropertySubject($subject);
        }
    }

    private function reindexPropertySubject(Property $property): void
    {
        $subject = $property->subject;

        if ($subject instanceof Directory || $subject instanceof File) {
            ReindexSearchDocument::dispatch($subject);
        }
    }
}

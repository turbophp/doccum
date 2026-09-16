<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ExtractionStatus;
use App\Jobs\ExtractText;
use App\Models\FileText;
use Illuminate\Console\Command;

/**
 * Re-queues extractions that never finished, without requiring the file to
 * be re-uploaded.
 *
 * Targets Failed and Pending rows only: a Done or Unsupported outcome is
 * already the honest final answer, and re-running it would waste work for
 * no new information.
 */
class ExtractRetry extends Command
{
    protected $signature = 'doccum:extract:retry';

    protected $description = 'Re-queue failed and pending text extractions';

    public function handle(): int
    {
        $rows = FileText::query()
            ->whereIn('status', [ExtractionStatus::Failed, ExtractionStatus::Pending])
            ->with('version')
            ->get();

        foreach ($rows as $row) {
            if ($row->version !== null) {
                ExtractText::dispatch($row->version);
            }
        }

        $this->components->info(sprintf('Re-queued %d extraction(s).', $rows->count()));

        return self::SUCCESS;
    }
}

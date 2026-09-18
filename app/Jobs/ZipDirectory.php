<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Directories\BuildDirectoryArchive;
use App\Enums\ArchiveStatus;
use App\Models\DirectoryArchive;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Builds one directory archive off the request thread.
 *
 * Zipping is measured in minutes for a directory of scans, because every
 * object is pulled out of storage one at a time. Doing it inline would hold a
 * PHP worker for the whole of it and then lose to the proxy's timeout with
 * nothing written, which is why the browser gets a row to poll instead.
 *
 * Whatever happens, the row ends in a terminal state. failed() is what makes
 * that true when the process is killed outright rather than throwing -- an
 * archive stuck on `building` for ever is indistinguishable from one still
 * being worked on, and the poller would never stop.
 */
class ZipDirectory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Ten minutes: long enough for a large directory, short enough that a
     * wedged job surfaces as a failure rather than occupying a worker all day.
     */
    public int $timeout = 600;

    public function __construct(public readonly DirectoryArchive $archive) {}

    public function handle(BuildDirectoryArchive $action): void
    {
        $action->handle($this->archive);
    }

    public function failed(?Throwable $e): void
    {
        $this->archive->forceFill([
            'status' => ArchiveStatus::Failed,
            'error' => $e?->getMessage() ?? 'The archive could not be built.',
        ])->save();
    }
}

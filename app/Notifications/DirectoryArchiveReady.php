<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\DirectoryArchive;
use Illuminate\Notifications\Notification;

/**
 * Tells the requester their zip is built and where to get it.
 *
 * Database channel only, deliberately. Zipping takes minutes, so the tab that
 * asked may well be closed by the time it finishes -- but this is not news
 * worth an email either, and a self-hosted instance may have no mailer at all
 * (see issue #161). The bell is the right loudness.
 */
class DirectoryArchiveReady extends Notification
{
    public function __construct(private readonly DirectoryArchive $archive) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'archive_id' => $this->archive->getKey(),
            'directory' => $this->archive->directory?->name,
            'size' => $this->archive->size,
            'url' => route('directories.archives.download', $this->archive),
        ];
    }
}

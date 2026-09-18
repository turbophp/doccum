<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ArchiveStatus;
use App\Models\DirectoryArchive;
use App\Services\DocumentStorage;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DirectoryArchiveDownloadController extends Controller
{
    public function __construct(private readonly DocumentStorage $storage) {}

    /**
     * Hand back a built archive, to the one person it was built for.
     *
     * The authorisation here is `requested_by`, NOT the directory's policy,
     * and that is the whole point: the zip already contains a fixed set of
     * bytes, chosen from what THAT viewer could reach at the moment the job
     * ran. Re-asking "may you view this directory now" would be the wrong
     * question in both directions. Someone who has since been granted the
     * directory would be handed an archive assembled under someone else's
     * reach, which may contain subtrees they still cannot enter; and the
     * requester whose access was narrowed afterwards would be refused bytes
     * that were legitimately theirs when they asked.
     *
     * So: the requester, or nobody.
     */
    public function __invoke(DirectoryArchive $archive): Response|StreamedResponse
    {
        abort_unless($archive->requested_by === auth()->id(), 403);

        abort_unless($archive->status === ArchiveStatus::Ready, 404);
        abort_if($archive->object_key === null, 404);
        abort_if($archive->hasExpired(), 410);

        abort_unless($this->storage->exists($archive->object_key), 404);

        $stream = $this->storage->disk()->readStream($archive->object_key);

        abort_if($stream === null, 404);

        return response()->streamDownload(
            function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            $archive->directory->name.'.zip',
            ['Content-Type' => 'application/zip'],
        );
    }
}

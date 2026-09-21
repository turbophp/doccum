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
     *
     * AND "NOBODY" NOW ANSWERS 404, NOT 403 -- item/reach-oracle-route-binding.
     * {archive} used to be an implicit model binding, so another user's
     * archive was resolved and then 403'd here while a nonexistent id 404'd
     * from the binding: an existence oracle over every archive id in the
     * instance, leaking how many archive jobs other people have and when.
     * The id is now an int, scoped to the requester INSIDE the query, so
     * both cases answer 404.
     *
     * The authorisation rule itself is unchanged -- requested_by, still not
     * the directory's policy, for every reason the paragraphs above give.
     * Only where it is enforced moved: from a refusal after the lookup into
     * the lookup itself.
     */
    public function __invoke(int $archive): Response|StreamedResponse
    {
        $model = DirectoryArchive::query()
            ->where('requested_by', auth()->id())
            ->findOrFail($archive);

        abort_unless($model->status === ArchiveStatus::Ready, 404);
        abort_if($model->object_key === null, 404);
        abort_if($model->hasExpired(), 410);

        abort_unless($this->storage->exists($model->object_key), 404);

        $stream = $this->storage->disk()->readStream($model->object_key);

        abort_if($stream === null, 404);

        return response()->streamDownload(
            function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            $model->directory->name.'.zip',
            ['Content-Type' => 'application/zip'],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ObjectMissingFromStorage;
use App\Models\FileVersion;
use App\Services\DocumentStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileVersionDownloadController extends Controller
{
    public function __construct(private readonly DocumentStorage $storage) {}

    /**
     * Authorise, then hand the client a short-lived signed URL for one
     * specific version of a file -- not necessarily its current one.
     *
     * Every version of a file shares its directory, uuid and period, so
     * authorize('download', $file) -- which routes through view() ->
     * liveDirectory() -- covers an old version exactly as well as the
     * current one, and the {file} route binding already 404s a trashed
     * file the same way FileDownloadController's does. The cross-file id
     * is the only new hole this controller opens, and the abort_if()
     * below is what closes it.
     *
     * {$version} is an int, not an implicit model binding, and it is
     * resolved AFTER authorize(). With implicit binding,
     * SubstituteBindings resolves it before this method ever runs, so an
     * unauthorised caller would get 404 for a nonexistent version id and
     * 403 for an existing one -- an existence oracle over every version id
     * in the instance. Unauthorised must always answer 403, never let the
     * shape of the response leak whether the id exists. This is the same
     * class of bug as open issue #109; do not reintroduce it here.
     *
     * AND {$file} IS NOW AN INT TOO, for the reason the paragraph above
     * gives about {$version} -- item/reach-oracle-route-binding. The record
     * used to say this controller "closes the oracle at 403", and that was
     * true of its VERSION id and false of its FILE id: the version is
     * resolved after authorize(), but the file was resolved by implicit
     * binding BEFORE it, so an unreachable file 403'd where a nonexistent
     * one 404'd. One oracle closed and another left open in the same
     * method. viewableFileOrFail() closes the second.
     */
    public function __invoke(int $file, int $version): RedirectResponse|Response|StreamedResponse
    {
        $model = $this->viewableFileOrFail($file);

        $this->authorize('download', $model);

        $fileVersion = FileVersion::query()->findOrFail($version);

        // A single, removable statement on purpose (see
        // .github/scripts/mutation-check.php, which only supports removing
        // an exact substring): $model->versions()->findOrFail(...) reads
        // nicer but folds the cross-file check into the query itself, where
        // a mutation cannot be expressed as a clean removal.
        //
        // (int) on both sides: file_id carries no cast in the database
        // itself, and a driver that returned it as a string would make a
        // strict !== comparison 404 every legitimate download while the
        // SQLite suite stayed green. FileVersion::casts() casts it too --
        // belt and braces on purpose, not redundancy.
        abort_if((int) $fileVersion->file_id !== (int) $model->getKey(), 404);

        if ($this->storage->servesPresignedUrls()) {
            return redirect()->away($this->storage->temporaryUrl($fileVersion));
        }

        // Spec 6 asks for a presigned redirect so the bytes never pass through
        // PHP, and that is still what happens whenever the endpoint names a
        // host the browser can reach. For embedded storage it does not -- the
        // default endpoint is loopback and the container publishes one port --
        // so the alternative to streaming here is a redirect to nowhere, which
        // is what issue #74 was. A Download link that works is worth more than
        // the invariant, and the invariant is kept everywhere it can be.
        //
        // The stream is opened HERE, before the response is built, not inside
        // the streamDownload() callback. Symfony runs that callback during
        // sendContent(), after the status line has already gone out, so a
        // missing object discovered in there cannot choose a status -- it can
        // only crash whatever response headers were already decided. Opening
        // it first turns "what status does a missing object get" back into a
        // decision instead of a race against the header flush (issue #134).
        try {
            $stream = $this->storage->readStream($fileVersion);
        } catch (ObjectMissingFromStorage) {
            Log::error(
                'Download failed: object missing from storage.',
                ['file_id' => $model->id, 'file_version_id' => $fileVersion->id, 'object_key' => $fileVersion->object_key],
            );

            // 502: object storage is genuinely upstream of PHP for the
            // MinIO/S3 case doccum ships with, so "the upstream returned
            // nothing" is honest there. For the embedded local disk it is a
            // stretch -- there is no real network hop -- but a stated stretch
            // beats an unhandled 500, and the alternative (404) would say
            // "this version doesn't exist," which is false: the row is
            // intact and every other version may be too.
            return response(
                "The stored object for this file could not be found in object storage (key: {$fileVersion->object_key}).",
                502,
            );
        }

        // streamDownload() rather than reading into memory: a document archive
        // has no useful size limit, and this path is the one spec 6 exists to
        // avoid, so it should at least not hold a whole file in a worker.
        return response()->streamDownload(
            function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            $model->name,
            ['Content-Type' => $fileVersion->mime ?? 'application/octet-stream'],
        );
    }
}

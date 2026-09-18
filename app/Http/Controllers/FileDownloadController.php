<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ObjectMissingFromStorage;
use App\Models\File;
use App\Services\DocumentStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileDownloadController extends Controller
{
    public function __construct(private readonly DocumentStorage $storage) {}

    /**
     * Authorise, then hand the client a short-lived signed URL.
     *
     * The bytes never pass through PHP, so a large document does not occupy a
     * worker or run into a memory limit. See spec §6.
     */
    public function __invoke(File $file): RedirectResponse|Response|StreamedResponse
    {
        $this->authorize('download', $file);

        abort_if($file->currentVersion === null, 404);

        $version = $file->currentVersion;

        if ($this->storage->servesPresignedUrls()) {
            return redirect()->away($this->storage->temporaryUrl($version));
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
            $stream = $this->storage->readStream($version);
        } catch (ObjectMissingFromStorage) {
            Log::error(
                'Download failed: object missing from storage.',
                ['file_id' => $file->id, 'file_version_id' => $version->id, 'object_key' => $version->object_key],
            );

            // 502: object storage is genuinely upstream of PHP for the
            // MinIO/S3 case doccum ships with, so "the upstream returned
            // nothing" is honest there. For the embedded local disk it is a
            // stretch -- there is no real network hop -- but a stated stretch
            // beats an unhandled 500, and the alternative (404) would say
            // "this file doesn't exist," which is false: the row and every
            // other version may be perfectly intact.
            return response(
                "The stored object for this file could not be found in object storage (key: {$version->object_key}).",
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
            $file->name,
            ['Content-Type' => $version->mime ?? 'application/octet-stream'],
        );
    }
}

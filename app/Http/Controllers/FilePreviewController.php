<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ObjectMissingFromStorage;
use App\Models\File;
use App\Services\DocumentStorage;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FilePreviewController extends Controller
{
    /**
     * Content types served inline. Everything else is refused rather than
     * guessed at, because "inline" is a promise about what a browser will do
     * with the bytes and that promise should only be made where it is known.
     *
     * text/html is deliberately absent -- see below.
     */
    private const INLINE_PREFIXES = ['image/', 'text/'];

    /**
     * Exact types allowed through beyond the prefixes above.
     *
     * The Word type is here so the preview can FETCH the bytes and convert
     * them in the browser (mammoth); nothing renders a .docx natively, and
     * converting server-side would mean LibreOffice in the image, which this
     * project has deliberately deferred.
     */
    private const INLINE_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function __construct(private readonly DocumentStorage $storage) {}

    /**
     * Stream a file's bytes for the preview dialog, inline.
     *
     * Separate from FileDownloadController because the two want opposite
     * things from a browser. Download wants a save dialog, so it answers
     * `Content-Disposition: attachment`, and where the object store can issue
     * a reachable presigned URL it redirects there so the bytes never touch
     * PHP. Neither is any use to a preview: an <iframe> pointed at an
     * attachment downloads the file instead of showing it, and a presigned
     * URL naming a host the browser cannot reach shows nothing at all. That
     * second case is the container exactly -- MinIO answers on loopback:9000
     * and the browser is on :8080 -- so a preview built on the download route
     * works on a dev machine and is blank in the shipped image.
     *
     * So this always streams, and always inline.
     */
    public function __invoke(File $file): Response|StreamedResponse
    {
        $this->authorize('view', $file);

        $version = $file->currentVersion;

        abort_if($version === null, 404);

        $mime = $version->mime ?? $file->mime ?? 'application/octet-stream';

        abort_unless(
            array_filter(self::INLINE_PREFIXES, fn (string $p): bool => str_starts_with($mime, $p))
                || in_array($mime, self::INLINE_TYPES, true),
            415,
        );

        try {
            $stream = $this->storage->readStream($version);
        } catch (ObjectMissingFromStorage) {
            return response('The stored object for this file is missing.', 502);
        }

        return response()->stream(
            function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="'.addslashes($file->name).'"',
                // The type above is a decision, not a hint: without this a
                // browser may sniff the bytes and render as HTML something
                // served as something else.
                'X-Content-Type-Options' => 'nosniff',

                // Every response from here is somebody's uploaded bytes,
                // served same-origin and rendered by a browser. `sandbox`
                // with no allow-scripts puts the document in an opaque origin
                // and stops its scripts executing -- so an uploaded HTML page
                // previewed here cannot read or act as whoever opened it.
                //
                // This replaces rewriting text/html to text/plain, which
                // achieved the same safety by making the preview useless for
                // the one type people most want to look at. The header holds
                // however the response is loaded, including a direct
                // navigation to the URL, which an iframe's own sandbox
                // attribute does not cover.
                'Content-Security-Policy' => "sandbox; default-src 'none'; img-src data: blob:; style-src 'unsafe-inline'",
                'Cache-Control' => 'private, max-age=0, must-revalidate',
            ],
        );
    }
}

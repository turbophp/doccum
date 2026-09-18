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

        // Uploaded HTML is served as plain text, never as HTML.
        //
        // This endpoint is same-origin with the application, so a document
        // rendered as text/html here runs its own scripts with the viewer's
        // session -- anyone able to upload a file could read or act as anyone
        // who previews it. Showing the markup as text is the whole feature
        // anyway: a preview is for looking at what a file contains.
        if (str_starts_with($mime, 'text/html') || str_contains($mime, 'xml')) {
            $mime = 'text/plain';
        }

        abort_unless(
            array_filter(self::INLINE_PREFIXES, fn (string $p): bool => str_starts_with($mime, $p))
                || $mime === 'application/pdf',
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
                // deliberately served as text.
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=0, must-revalidate',
            ],
        );
    }
}

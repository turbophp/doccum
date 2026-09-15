<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\File;
use App\Services\DocumentStorage;
use Illuminate\Http\RedirectResponse;

class FileDownloadController extends Controller
{
    public function __construct(private readonly DocumentStorage $storage) {}

    /**
     * Authorise, then hand the client a short-lived signed URL.
     *
     * The bytes never pass through PHP, so a large document does not occupy a
     * worker or run into a memory limit. See spec §6.
     */
    public function __invoke(File $file): RedirectResponse
    {
        $this->authorize('download', $file);

        abort_if($file->currentVersion === null, 404);

        return redirect()->away($this->storage->temporaryUrl($file->currentVersion));
    }
}

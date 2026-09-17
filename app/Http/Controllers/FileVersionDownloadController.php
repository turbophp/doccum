<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\FileVersion;
use App\Services\DocumentStorage;
use Illuminate\Http\RedirectResponse;

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
     */
    public function __invoke(File $file, int $version): RedirectResponse
    {
        $this->authorize('download', $file);

        $fileVersion = FileVersion::query()->findOrFail($version);

        // A single, removable statement on purpose (see
        // .github/scripts/mutation-check.php, which only supports removing
        // an exact substring): $file->versions()->findOrFail(...) reads
        // nicer but folds the cross-file check into the query itself, where
        // a mutation cannot be expressed as a clean removal.
        //
        // (int) on both sides: file_id carries no cast in the database
        // itself, and a driver that returned it as a string would make a
        // strict !== comparison 404 every legitimate download while the
        // SQLite suite stayed green. FileVersion::casts() casts it too --
        // belt and braces on purpose, not redundancy.
        abort_if((int) $fileVersion->file_id !== (int) $file->getKey(), 404);

        return redirect()->away($this->storage->temporaryUrl($fileVersion));
    }
}

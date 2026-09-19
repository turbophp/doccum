<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Files\MoveFile;
use App\Actions\Files\RenameFile;
use App\Actions\Files\TrashFile;
use App\Exceptions\DuplicateFileName;
use App\Http\Resources\Api\V1\FileResource;
use Illuminate\Http\Request;

/**
 * Spec §11's file endpoints, minus upload/commit/download-url/versions/text
 * (their own controllers). Every method authorises through
 * App\Policies\FilePolicy, exactly as App\Livewire\Files\Browser does for
 * the same actions.
 */
class FileController extends Controller
{
    /** GET /api/v1/files/{id} */
    public function show(int $file): FileResource
    {
        $model = $this->viewableFileOrFail($file);

        $this->authorize('view', $model);

        return new FileResource($model);
    }

    /** PATCH /api/v1/files/{id} -- rename and/or move. */
    public function update(Request $request, int $file): FileResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'directory_id' => ['sometimes', 'integer'],
        ]);

        $model = $this->viewableFileOrFail($file);

        if (array_key_exists('name', $validated)) {
            $this->authorize('update', $model);

            try {
                $model = app(RenameFile::class)->handle($model, $validated['name']);
            } catch (DuplicateFileName $e) {
                $this->fail($e->getMessage(), 'name');
            }
        }

        if (array_key_exists('directory_id', $validated)) {
            // Scoped the same as the destination everywhere else in this
            // API: outside the caller's reach entirely 404s, same as issue
            // #109 settles for App\Livewire\Files\Browser::moveFile().
            $destination = $this->viewableDirectoryOrFail((int) $validated['directory_id']);

            $this->authorize('move', [$model, $destination]);

            try {
                $model = app(MoveFile::class)->handle($model, $destination);
            } catch (DuplicateFileName $e) {
                $this->fail($e->getMessage(), 'directory_id');
            }
        }

        return new FileResource($model);
    }

    /** DELETE /api/v1/files/{id} -- soft delete (trash). */
    public function destroy(int $file): FileResource
    {
        $model = $this->viewableFileOrFail($file);

        $this->authorize('delete', $model);

        app(TrashFile::class)->handle($model);

        return new FileResource($model->refresh());
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Directories\RestoreDirectory;
use App\Actions\Files\RestoreFile;
use App\Exceptions\DuplicateDirectoryName;
use App\Exceptions\DuplicateFileName;
use App\Exceptions\PeriodIsArchived;
use App\Http\Resources\Api\V1\DirectoryResource;
use App\Http\Resources\Api\V1\FileResource;
use App\Models\Directory;
use App\Models\File;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * POST /api/v1/trash/{type}/{id}/restore -- spec §11.
 *
 * `{type}` is "file" or "directory"; anything else 404s the same as a
 * genuinely unknown id -- there is nothing to leak either way. Both
 * branches are scoped and authorised exactly as
 * App\Livewire\Trash\Index::restoreFile()/restoreDirectory() are.
 */
class TrashRestoreController extends Controller
{
    public function store(string $type, int $id): JsonResource
    {
        return match ($type) {
            'file' => $this->restoreFile($id),
            'directory' => $this->restoreDirectory($id),
            default => abort(404),
        };
    }

    private function restoreFile(int $id): FileResource
    {
        $access = $this->access();
        $user = $this->user();

        // The same union as TrashController::index() and
        // App\Livewire\Trash\Index::render(): a trashed file's directory
        // may be live or itself trashed alongside it.
        $reachableDirectoryIds = array_values(array_unique([
            ...$access->viewableDirectoryIds($user),
            ...$access->viewableTrashedDirectoryIds($user),
        ]));

        $file = File::onlyTrashed()->whereIn('directory_id', $reachableDirectoryIds)->findOrFail($id);

        $this->authorize('restore', $file);

        try {
            $file = app(RestoreFile::class)->handle($file);
        } catch (DuplicateFileName|PeriodIsArchived $e) {
            $this->fail($e->getMessage());
        }

        return new FileResource($file);
    }

    private function restoreDirectory(int $id): DirectoryResource
    {
        $trashedDirectoryIds = $this->access()->viewableTrashedDirectoryIds($this->user());

        $directory = Directory::onlyTrashed()->whereIn('id', $trashedDirectoryIds)->findOrFail($id);

        $this->authorize('restore', $directory);

        try {
            $directory = app(RestoreDirectory::class)->handle($directory);
        } catch (DuplicateDirectoryName $e) {
            $this->fail($e->getMessage());
        }

        return new DirectoryResource($directory);
    }
}

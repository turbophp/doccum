<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\FileResource;
use App\Models\File;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** GET /api/v1/directories/{id}/files -- spec §11. */
class DirectoryFileController extends Controller
{
    public function index(int $directory): AnonymousResourceCollection
    {
        $model = $this->viewableDirectoryOrFail($directory);

        $this->authorize('view', $model);

        $files = File::query()
            ->where('directory_id', $model->getKey())
            ->orderBy('name')
            ->cursorPaginate();

        return FileResource::collection($files);
    }
}

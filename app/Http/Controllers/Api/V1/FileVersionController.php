<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\FileVersionResource;
use App\Models\FileVersion;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/files/{id}/versions -- spec §11.
 *
 * item/api-presigned-upload (issue #57) owns
 * POST /api/v1/files/{id}/versions/upload-url -- the upload_id it would
 * mint, and the StoreFileVersion entry point (issue #115) it would commit
 * through, are not built on this branch. This controller is deliberately
 * read-only until that item lands.
 */
class FileVersionController extends Controller
{
    public function index(int $file): AnonymousResourceCollection
    {
        $model = $this->viewableFileOrFail($file);

        $this->authorize('view', $model);

        $versions = FileVersion::query()
            ->where('file_id', $model->getKey())
            ->orderByDesc('version_number')
            ->cursorPaginate();

        return FileVersionResource::collection($versions);
    }
}

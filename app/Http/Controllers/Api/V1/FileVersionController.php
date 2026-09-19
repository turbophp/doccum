<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Files\CreateUploadUrl;
use App\Http\Resources\Api\V1\FileVersionResource;
use App\Models\FileVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/files/{id}/versions and POST /api/v1/files/{id}/versions/upload-url
 * -- spec §11.
 *
 * There is no separate "commit a version" route in the spec's own table:
 * FileCommitController's POST /files already creates a file on first
 * commit of a name and adds a version on every later one
 * (App\Actions\Files\CommitUpload). storeUploadUrl() below reuses that by
 * minting an upload_id bound to THIS file's own directory and name --
 * never a name the request supplies -- so the client cannot use a version
 * upload URL to commit under a different name than the file it asked to
 * add a version to. That is a genuine reading of an underspecified route
 * table, not the spec's own words; see this item's report.
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

    public function storeUploadUrl(Request $request, int $file): JsonResponse
    {
        $validated = $request->validate([
            'mime' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:0'],
        ]);

        $model = $this->viewableFileOrFail($file);

        $this->authorize('replace', $model);

        $result = app(CreateUploadUrl::class)->handle(
            $this->user(),
            $model->directory,
            $model->name,
            $validated['mime'],
            (int) $validated['size'],
        );

        return response()->json(['data' => $result], 201);
    }
}

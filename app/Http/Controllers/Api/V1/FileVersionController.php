<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Files\CreateUploadUrl;
use App\Exceptions\PeriodIsArchived;
use App\Http\Resources\Api\V1\FileVersionResource;
use App\Models\FileVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/files/{id}/versions, and (item/api-presigned-upload, issue
 * #24) POST /api/v1/files/{id}/versions/upload-url -- step 1 of spec §11's
 * upload flow for a version of a file already known by id, the twin of
 * FileUploadUrlController for a brand new file. FileCommitController's
 * `file_id` branch is step 3, appending through StoreFileVersion::replace()
 * (issue #115) rather than handle() -- see that controller's own docblock.
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

    /** POST /api/v1/files/{id}/versions/upload-url */
    public function storeUploadUrl(Request $request, int $file): JsonResponse
    {
        $validated = $request->validate([
            'mime' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:0'],
        ]);

        $model = $this->viewableFileOrFail($file);

        // The same ability Browser::replaceFile() authorises a version
        // upload against: files.upload plus edit reach, and refused
        // outright for a file that is itself trashed. See FilePolicy::
        // replace()'s own docblock.
        $this->authorize('replace', $model);

        try {
            $result = app(CreateUploadUrl::class)->forVersion(
                $this->user(),
                $model,
                $validated['mime'],
                (int) $validated['size'],
            );
        } catch (PeriodIsArchived $e) {
            $this->fail($e->getMessage(), 'file_id');
        }

        return response()->json(['data' => $result], 201);
    }
}

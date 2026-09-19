<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\DocumentStorage;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/files/{id}/download-url -- spec §11: "Downloads mirror this:
 * ... runs the policy check and returns a short-lived presigned GET."
 *
 * Unlike App\Http\Controllers\FileDownloadController, there is no
 * streaming fallback for a presigned URL the caller cannot reach: that
 * fallback exists for a BROWSER on the other end of a redirect (issue #74),
 * and an API caller is handed the URL as data to do with as it chooses --
 * fetch it directly, log it, hand it to something else -- so PHP guessing
 * at reachability on its behalf does not apply the same way. See this
 * item's report.
 */
class FileDownloadUrlController extends Controller
{
    public function __construct(private readonly DocumentStorage $storage) {}

    public function show(int $file): JsonResponse
    {
        $model = $this->viewableFileOrFail($file);

        $this->authorize('download', $model);

        abort_if($model->currentVersion === null, 404);

        return response()->json(['data' => [
            'url' => $this->storage->temporaryUrl($model->currentVersion),
            'expires_in' => 300,
        ]]);
    }
}

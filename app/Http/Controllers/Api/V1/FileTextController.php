<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\FileTextResource;

/** GET /api/v1/files/{id}/text -- extraction status + text, spec §11. */
class FileTextController extends Controller
{
    public function show(int $file): FileTextResource
    {
        $model = $this->viewableFileOrFail($file);

        $this->authorize('view', $model);

        $text = $model->currentVersion?->text;

        abort_if($text === null, 404);

        return new FileTextResource($text);
    }
}

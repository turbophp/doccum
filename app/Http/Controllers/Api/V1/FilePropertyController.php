<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Properties\SetProperties;
use App\Exceptions\UnknownProperty;
use App\Http\Resources\Api\V1\FileResource;
use Illuminate\Http\Request;

/**
 * PUT /api/v1/files/{id}/properties -- spec §11. See
 * DirectoryPropertyController's own docblock; this is its file-shaped twin.
 */
class FilePropertyController extends Controller
{
    public function update(Request $request, int $file): FileResource
    {
        $model = $this->viewableFileOrFail($file);

        $this->authorize('update', $model);

        $validated = $request->validate(['values' => ['present', 'array']]);

        try {
            app(SetProperties::class)->handle($model, $validated['values']);
        } catch (UnknownProperty $e) {
            $this->fail($e->getMessage(), 'values');
        }

        return new FileResource($model->refresh());
    }
}

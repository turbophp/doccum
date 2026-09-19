<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Properties\SetProperties;
use App\Exceptions\UnknownProperty;
use App\Http\Resources\Api\V1\DirectoryResource;
use Illuminate\Http\Request;

/**
 * PUT /api/v1/directories/{id}/properties -- spec §11.
 *
 * Gated exactly as App\Livewire\Files\PropertyPanel::save() is: `update` on
 * the subject, nothing else. SetProperties itself validates each value
 * against its own definition's rules and writes all-or-nothing; a genuine
 * Laravel ValidationException from THAT is left to propagate as-is, since
 * it already IS the standard shape spec §11 asks for.
 */
class DirectoryPropertyController extends Controller
{
    public function update(Request $request, int $directory): DirectoryResource
    {
        $model = $this->viewableDirectoryOrFail($directory);

        $this->authorize('update', $model);

        $validated = $request->validate(['values' => ['present', 'array']]);

        try {
            app(SetProperties::class)->handle($model, $validated['values']);
        } catch (UnknownProperty $e) {
            $this->fail($e->getMessage(), 'values');
        }

        return new DirectoryResource($model->refresh());
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Files\CreateUploadUrl;
use App\Exceptions\PeriodIsArchived;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/files/upload-url -- step 1 of spec §11's upload flow, for a
 * brand new file (or a new version of one addressed by name).
 * FileVersionController::storeUploadUrl is the twin for adding a version to
 * a file already known by id.
 */
class FileUploadUrlController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'directory_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:0'],
        ]);

        $directory = $this->viewableDirectoryOrFail((int) $validated['directory_id']);

        // create() on File::class, not on an existing model -- the same
        // [File::class, $directory] shape App\Livewire\Files\Browser::
        // store() already uses for "may this user create a File in this
        // Directory at all".
        $this->authorize('create', [File::class, $directory]);

        try {
            $result = app(CreateUploadUrl::class)->handle(
                $this->user(),
                $directory,
                $validated['name'],
                $validated['mime'],
                (int) $validated['size'],
            );
        } catch (PeriodIsArchived $e) {
            $this->fail($e->getMessage(), 'directory_id');
        }

        return response()->json(['data' => $result], 201);
    }
}

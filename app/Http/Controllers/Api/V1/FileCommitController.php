<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Files\CommitUpload;
use App\Exceptions\ObjectMissingFromStorage;
use App\Exceptions\PeriodIsArchived;
use App\Exceptions\UploadChecksumMismatch;
use App\Exceptions\UploadIdExpired;
use App\Exceptions\UploadIdInvalid;
use App\Exceptions\UploadSizeMismatch;
use App\Http\Resources\Api\V1\FileResource;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/files -- step 3 of spec §11's upload flow: commits an object
 * already PUT to staging (steps 1 and 2 are FileUploadUrlController and the
 * client's own direct PUT). Creates the file on first commit of a name,
 * adds a version on every subsequent one -- see App\Actions\Files\
 * CommitUpload's own docblock.
 */
class FileCommitController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'directory_id' => ['required', 'integer'],
            'upload_id' => ['required', 'string'],
            'checksum' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        $directory = $this->viewableDirectoryOrFail((int) $validated['directory_id']);

        $this->authorize('create', [File::class, $directory]);

        try {
            $file = app(CommitUpload::class)->handle(
                $this->user(),
                $directory,
                $validated['upload_id'],
                $validated['checksum'],
            );
        } catch (UploadIdInvalid|UploadIdExpired $e) {
            $this->fail($e->getMessage(), 'upload_id');
        } catch (ObjectMissingFromStorage|UploadSizeMismatch|UploadChecksumMismatch $e) {
            $this->fail($e->getMessage(), 'upload_id');
        } catch (PeriodIsArchived $e) {
            $this->fail($e->getMessage(), 'directory_id');
        }

        return FileResource::make($file)->response()->setStatusCode(201);
    }
}

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
 * already PUT to staging (steps 1 and 2 are FileUploadUrlController /
 * FileVersionController::storeUploadUrl, and the client's own direct PUT).
 *
 * One route serves both flows the upload_id can carry, discriminated by
 * which of `directory_id` or `file_id` the commit request itself names --
 * exactly one, never both, never neither:
 *
 *   - `directory_id`: creates the file on first commit of a name, adds a
 *     version on every subsequent one, through StoreFileVersion::handle()
 *     (App\Actions\Files\CommitUpload::handle()).
 *   - `file_id`: appends a version to a file already known by id, through
 *     StoreFileVersion::replace() -- which cannot create a file
 *     (CommitUpload::forVersion(), issue #115).
 *
 * Authorised accordingly: FilePolicy::create() against the resolved
 * directory for the first, FilePolicy::replace() against the resolved file
 * for the second -- the identical pair CreateUploadUrl's own two entry
 * points are authorised against at the url step.
 */
class FileCommitController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'directory_id' => ['required_without:file_id', 'prohibits:file_id', 'integer'],
            'file_id' => ['required_without:directory_id', 'prohibits:directory_id', 'integer'],
            'upload_id' => ['required', 'string'],
            'checksum' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        $isVersion = array_key_exists('file_id', $validated);

        try {
            if ($isVersion) {
                $model = $this->viewableFileOrFail((int) $validated['file_id']);

                $this->authorize('replace', $model);

                $file = app(CommitUpload::class)->forVersion(
                    $this->user(),
                    $model,
                    $validated['upload_id'],
                    $validated['checksum'],
                );
            } else {
                $directory = $this->viewableDirectoryOrFail((int) $validated['directory_id']);

                $this->authorize('create', [File::class, $directory]);

                $file = app(CommitUpload::class)->handle(
                    $this->user(),
                    $directory,
                    $validated['upload_id'],
                    $validated['checksum'],
                );
            }
        } catch (UploadIdInvalid|UploadIdExpired|ObjectMissingFromStorage|UploadSizeMismatch|UploadChecksumMismatch $e) {
            $this->fail($e->getMessage(), 'upload_id');
        } catch (PeriodIsArchived $e) {
            $this->fail($e->getMessage(), $isVersion ? 'file_id' : 'directory_id');
        }

        return FileResource::make($file)->response()->setStatusCode(201);
    }
}

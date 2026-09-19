<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DirectoryController;
use App\Http\Controllers\Api\V1\DirectoryFileController;
use App\Http\Controllers\Api\V1\DirectoryPropertyController;
use App\Http\Controllers\Api\V1\FileCommitController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\FileDownloadUrlController;
use App\Http\Controllers\Api\V1\FilePropertyController;
use App\Http\Controllers\Api\V1\FileTextController;
use App\Http\Controllers\Api\V1\FileUploadUrlController;
use App\Http\Controllers\Api\V1\FileVersionController;
use App\Http\Controllers\Api\V1\PropertyDefinitionController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\TrashController;
use App\Http\Controllers\Api\V1\TrashRestoreController;
use Illuminate\Support\Facades\Route;

/**
 * item/api-content (issue #23), spec §11: Laravel Sanctum personal access
 * tokens, every route under /api/v1, three gates on every one of them --
 * `auth:sanctum` (is this a real, unexpired token at all), `ability:...`
 * (does the TOKEN carry the scope this route needs), and each controller's
 * own `$this->authorize(...)` call against the same Policy the UI uses
 * (does the PERSON behind the token, and the directory ACL, allow it).
 *
 * Content operations only, by construction: nothing here touches users,
 * roles, property-DEFINITION writes (GET /property-definitions is
 * read-only), or archive periods -- no route in this file names a users,
 * roles, or periods controller, and PropertyDefinitionController exposes
 * only index(). tests/Feature/Api/RouteSurfaceTest.php reads this file's
 * OWN registered routes back through the router and asserts that, rather
 * than trusting a comment here to stay true.
 *
 * `throttle:api` is spec §11's "rate-limited per token" --
 * DoccumServiceProvider::boot() defines the 'api' limiter keyed on the
 * token's own id. It is named explicitly here rather than assumed:
 * bootstrap/app.php's `api:` wraps this whole file in Laravel's own 'api'
 * middleware group, but that group carries throttle:api only when
 * $middleware->throttleApi() is called (Illuminate\Foundation\
 * Configuration\Middleware::defaultMiddleware()), which bootstrap/app.php
 * does not do -- so this line is what actually adds it, not the wrapping.
 */
Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('v1')->name('api.v1.')->group(function (): void {
    Route::middleware('ability:directories:read')->group(function (): void {
        Route::get('directories', [DirectoryController::class, 'index'])->name('directories.index');
        Route::get('directories/{directory}', [DirectoryController::class, 'show'])->name('directories.show');
        Route::get('directories/{directory}/files', [DirectoryFileController::class, 'index'])->name('directories.files');
    });

    Route::middleware('ability:directories:write')->group(function (): void {
        Route::post('directories', [DirectoryController::class, 'store'])->name('directories.store');
        Route::patch('directories/{directory}', [DirectoryController::class, 'update'])->name('directories.update');
        Route::delete('directories/{directory}', [DirectoryController::class, 'destroy'])->name('directories.destroy');
    });

    // item/api-presigned-upload (issue #24): the upload-url and commit
    // routes. POST files/upload-url and POST files/{file}/versions/
    // upload-url are step 1 of spec §11's flow (App\Actions\Files\
    // CreateUploadUrl); POST files is step 3 (App\Actions\Files\
    // CommitUpload), which appends through StoreFileVersion::replace() --
    // the entry point issue #115 asks for, taking the File itself and
    // unable to create one -- when the commit names a file_id, and through
    // StoreFileVersion::handle() when it names a directory_id. See
    // FileCommitController's own docblock for the discriminator.
    Route::middleware('ability:files:write')->group(function (): void {
        Route::patch('files/{file}', [FileController::class, 'update'])->name('files.update');
        Route::post('files/upload-url', [FileUploadUrlController::class, 'store'])->name('files.upload-url');
        Route::post('files', [FileCommitController::class, 'store'])->name('files.store');
        Route::post('files/{file}/versions/upload-url', [FileVersionController::class, 'storeUploadUrl'])->name('files.versions.upload-url');
    });

    Route::middleware('ability:files:read')->group(function (): void {
        Route::get('files/{file}', [FileController::class, 'show'])->name('files.show');
        Route::get('files/{file}/download-url', [FileDownloadUrlController::class, 'show'])->name('files.download-url');
        Route::get('files/{file}/versions', [FileVersionController::class, 'index'])->name('files.versions.index');
        Route::get('files/{file}/text', [FileTextController::class, 'show'])->name('files.text');
    });

    Route::middleware('ability:files:delete')->delete('files/{file}', [FileController::class, 'destroy'])->name('files.destroy');

    Route::middleware('ability:properties:read')
        ->get('property-definitions', [PropertyDefinitionController::class, 'index'])
        ->name('property-definitions.index');

    Route::middleware('ability:properties:write')->group(function (): void {
        Route::put('directories/{directory}/properties', [DirectoryPropertyController::class, 'update'])->name('directories.properties.update');
        Route::put('files/{file}/properties', [FilePropertyController::class, 'update'])->name('files.properties.update');
    });

    Route::middleware('ability:search')->get('search', [SearchController::class, 'index'])->name('search');

    // Trash mixes files and directories, and the fixed eight abilities
    // (App\Enums\ApiTokenAbility) have no "trash" scope of its own -- a
    // deliberate reading, flagged in this item's report rather than
    // invented silently: listing requires read on BOTH subject kinds,
    // restoring requires write on BOTH, since {type} in the restore route
    // is not known until the controller runs and a token narrows, never
    // widens, regardless of which branch a request happens to take.
    Route::middleware(['ability:files:read', 'ability:directories:read'])
        ->get('trash', [TrashController::class, 'index'])
        ->name('trash.index');

    Route::middleware(['ability:files:write', 'ability:directories:write'])
        ->post('trash/{type}/{id}/restore', [TrashRestoreController::class, 'store'])
        ->whereIn('type', ['file', 'directory'])
        ->whereNumber('id')
        ->name('trash.restore');
});

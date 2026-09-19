<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\DirectoryResource;
use App\Http\Resources\Api\V1\FileResource;
use App\Models\Directory;
use App\Models\File;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/trash -- spec §11.
 *
 * The exact query shape App\Livewire\Trash\Index::render() already uses:
 * DirectoryAccess::viewableTrashedDirectoryIds() for the trashed
 * directories half, and the UNION of that with viewableDirectoryIds() for
 * trashed files, because a trashed file's own directory may be live
 * (trashed independently of it) or itself part of the trashed batch. See
 * that class's own docblock for why both halves are needed and why neither
 * is filtered in the view.
 *
 * No pagination, unlike every other listing in this API: the two result
 * sets are different models entered under one `data` key, which cursor
 * pagination has no single cursor for without inventing a composite one --
 * out of this item's scope. See this item's report.
 */
class TrashController extends Controller
{
    public function index(): JsonResponse
    {
        $access = $this->access();
        $user = $this->user();

        $trashedDirectoryIds = $access->viewableTrashedDirectoryIds($user);

        $reachableDirectoryIds = array_values(array_unique([
            ...$access->viewableDirectoryIds($user),
            ...$trashedDirectoryIds,
        ]));

        $files = File::onlyTrashed()
            ->whereIn('directory_id', $reachableDirectoryIds)
            ->orderByDesc('deleted_at')
            ->get();

        $directories = Directory::onlyTrashed()
            ->whereIn('id', $trashedDirectoryIds)
            ->orderByDesc('deleted_at')
            ->get();

        return response()->json(['data' => [
            'files' => FileResource::collection($files),
            'directories' => DirectoryResource::collection($directories),
        ]]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Directories\CreateDirectory;
use App\Actions\Directories\MoveDirectory;
use App\Actions\Directories\RenameDirectory;
use App\Actions\Directories\TrashDirectory;
use App\Exceptions\CannotMoveDirectoryIntoItself;
use App\Exceptions\DuplicateDirectoryName;
use App\Http\Resources\Api\V1\DirectoryResource;
use App\Models\Directory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Spec §11's directory endpoints. Every method authorises through
 * App\Policies\DirectoryPolicy, exactly as App\Livewire\Files\Browser does
 * for the same actions -- CLAUDE.md: actions never authorise, callers do.
 */
class DirectoryController extends Controller
{
    /** GET /api/v1/directories?parent_id= */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer'],
        ]);

        $viewableIds = $this->access()->viewableDirectoryIds($this->user());

        $directories = Directory::query()
            ->whereIn('id', $viewableIds)
            ->when(
                array_key_exists('parent_id', $validated),
                fn (Builder $query) => $query->where('parent_id', $validated['parent_id']),
            )
            ->orderBy('name')
            ->cursorPaginate();

        return DirectoryResource::collection($directories);
    }

    /** POST /api/v1/directories */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        if (($validated['parent_id'] ?? null) !== null) {
            $parent = $this->viewableDirectoryOrFail((int) $validated['parent_id']);
            $this->authorize('create', $parent);
        } else {
            $parent = null;

            // DirectoryPolicy::create() cannot be asked about a null
            // parent -- it takes a Directory, not ?Directory -- because
            // there is no destination to check Edit access on at the
            // root. The permission half of that same check still applies,
            // so it is asked directly here rather than skipped, unlike
            // App\Livewire\Files\Browser::createDirectory(), which asks
            // nothing at all for this case. See this item's report for why
            // the API is stricter here than the existing UI surface.
            abort_unless($this->user()->can('directories.create'), 403);
        }

        try {
            $directory = app(CreateDirectory::class)->handle($this->user(), $validated['name'], $parent);
        } catch (DuplicateDirectoryName $e) {
            $this->fail($e->getMessage(), 'name');
        }

        return DirectoryResource::make($directory)->response()->setStatusCode(201);
    }

    /** GET /api/v1/directories/{id} */
    public function show(int $directory): DirectoryResource
    {
        $model = $this->viewableDirectoryOrFail($directory);

        $this->authorize('view', $model);

        return new DirectoryResource($model);
    }

    /** PATCH /api/v1/directories/{id} -- rename and/or move. */
    public function update(Request $request, int $directory): DirectoryResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $model = $this->viewableDirectoryOrFail($directory);

        if (array_key_exists('name', $validated)) {
            $this->authorize('update', $model);

            try {
                $model = app(RenameDirectory::class)->handle($model, $validated['name']);
            } catch (DuplicateDirectoryName $e) {
                $this->fail($e->getMessage(), 'name');
            }
        }

        if (array_key_exists('parent_id', $validated)) {
            // Scoped the same way the destination -- not this directory --
            // is scoped everywhere else in this controller: outside the
            // caller's reach entirely 404s, same as issue #109 settles for
            // App\Livewire\Files\Browser::moveDirectory().
            $destination = $validated['parent_id'] === null
                ? null
                : $this->viewableDirectoryOrFail((int) $validated['parent_id']);

            $this->authorize('move', [$model, $destination]);

            try {
                $model = app(MoveDirectory::class)->handle($model, $destination);
            } catch (DuplicateDirectoryName|CannotMoveDirectoryIntoItself $e) {
                $this->fail($e->getMessage(), 'parent_id');
            }
        }

        return new DirectoryResource($model);
    }

    /** DELETE /api/v1/directories/{id} -- soft delete. */
    public function destroy(int $directory): DirectoryResource
    {
        $model = $this->viewableDirectoryOrFail($directory);

        $this->authorize('delete', $model);

        $model = app(TrashDirectory::class)->handle($model);

        return new DirectoryResource($model);
    }
}

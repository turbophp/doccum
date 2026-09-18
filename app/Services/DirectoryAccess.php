<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The single source of truth for per-directory access. See spec §5.
 *
 * Grants are inherited by a directory's entire subtree, and the effective level
 * is the HIGHEST grant found on the directory or any ancestor, across grants
 * made to the user directly and to any role they hold.
 *
 * Grant-only: there are no deny rules. Deny semantics in an inherited tree
 * produce surprising results and expensive resolution; a narrower grant lower
 * in the tree covers the legitimate cases.
 */
class DirectoryAccess
{
    /** @var array<string, AccessLevel|null> */
    private array $levels = [];

    /** @var array<int, array<int, int>> */
    private array $viewable = [];

    /** @var array<int, string>|null */
    private ?array $trashedPaths = null;

    public function levelFor(User $user, Directory $directory): ?AccessLevel
    {
        $key = $user->getKey().':'.$directory->getKey();

        // array_key_exists, not ??=: a denial resolves to null, and ??= treats a
        // null-valued entry as absent, so every "no access" answer would be
        // recomputed on every call. Listings are mostly denials for most users,
        // so that is the common path, not the rare one.
        if (! array_key_exists($key, $this->levels)) {
            $this->levels[$key] = $this->resolve($user, $directory);
        }

        return $this->levels[$key];
    }

    public function can(User $user, Directory $directory, AccessLevel $required): bool
    {
        return $this->levelFor($user, $directory)?->allows($required) ?? false;
    }

    /**
     * Every directory id the user may at least view, granted subtrees expanded.
     *
     * This is what search filters against, so it must never include a directory
     * the user cannot reach.
     *
     * @return array<int, int>
     */
    public function viewableDirectoryIds(User $user): array
    {
        return $this->viewable[$user->getKey()] ??= $this->resolveViewable($user);
    }

    /**
     * The tops of what a viewer may reach from /files: every viewable
     * directory whose parent is NOT viewable, including one whose parent
     * is null. A grant made directly on a nested directory, with no grant
     * anywhere on its ancestors, makes THAT directory a reach root -- it
     * is otherwise reachable only by typing its URL. See spec §10 and
     * issue #99.
     *
     * Resolved from viewableDirectoryIds() above, never a fresh
     * per-directory can() loop: one query for the candidate set's own
     * parent_id column, checked in memory against the same viewable set
     * everything else here is built from.
     *
     * @return array<int, int>
     */
    public function reachRootIds(User $user): array
    {
        $viewable = $this->viewableDirectoryIds($user);

        if ($viewable === []) {
            return [];
        }

        $viewableSet = array_fill_keys($viewable, true);

        return Directory::query()
            ->whereIn('id', $viewable)
            ->get(['id', 'parent_id'])
            ->filter(static fn (Directory $directory): bool => $directory->parent_id === null
                || ! isset($viewableSet[$directory->parent_id]))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * The Files sidebar's whole tree: every reach root (reachRootIds()
     * above) with its own viewable descendants nested under it via the
     * `children` relation, alphabetical at every level. This is the
     * single place that decides what the sidebar tree contains -- Browser
     * and the view consume the result and filter nothing further
     * (CLAUDE.md: filter in the query, never in the view).
     *
     * Every non-root viewable directory's parent is, by definition of
     * "not a root" here, ALSO viewable -- so it is guaranteed to already
     * be a key in $byId, and the lookup below cannot miss.
     *
     * @return Collection<int, Directory>
     */
    public function reachTree(User $user): Collection
    {
        $viewable = $this->viewableDirectoryIds($user);

        if ($viewable === []) {
            return new Collection;
        }

        $roots = array_fill_keys($this->reachRootIds($user), true);

        $byId = Directory::query()
            ->whereIn('id', $viewable)
            ->orderBy('name')
            ->get()
            ->each(static function (Directory $directory): void {
                $directory->setRelation('children', new Collection);
            })
            ->keyBy('id');

        $tree = new Collection;

        foreach ($byId as $directory) {
            if (isset($roots[$directory->getKey()])) {
                $tree->push($directory);
            } else {
                $byId->get($directory->parent_id)->children->push($directory);
            }
        }

        return $tree;
    }

    private function resolve(User $user, Directory $directory): ?AccessLevel
    {
        if ($user->can('directories.view-all')) {
            return AccessLevel::Manage;
        }

        // A trashed directory hides its own subtree from every grant that
        // reaches it from above, even one sitting on a live ancestor further
        // up. This is deliberately checked against PROPER ancestors only --
        // the directory's own id, always the last segment of its own path,
        // is excluded -- because the directory's own trashed state must not
        // block resolving access ON it: restoring it is exactly the manage
        // check this guards, and it can only ever be reached while trashed.
        // See issue #49.
        if ($this->hasTrashedProperAncestor($directory)) {
            return null;
        }

        $levels = $this->grantsFor($user)
            ->whereIn('directory_id', $directory->ancestorIds())
            ->pluck('level')
            ->all();

        return AccessLevel::highest($levels);
    }

    /** @return array<int, int> */
    private function resolveViewable(User $user): array
    {
        if ($user->can('directories.view-all')) {
            return Directory::query()->pluck('id')->all();
        }

        $paths = Directory::query()
            ->whereIn('id', $this->grantsFor($user)->pluck('directory_id'))
            ->pluck('path');

        if ($paths->isEmpty()) {
            return [];
        }

        $trashedPaths = $this->trashedPaths();

        return Directory::query()
            ->where(function (Builder $query) use ($paths): void {
                foreach ($paths as $path) {
                    $query->orWhere('path', 'like', $path.'%');
                }
            })
            // A live directory whose path is prefixed by a trashed one is a
            // descendant of something that no longer exists as far as the
            // browser and search are concerned, no matter how it got there
            // -- a grant made directly on it, or one inherited from a live
            // ancestor above the trashed node. See issue #49.
            ->when($trashedPaths !== [], function (Builder $query) use ($trashedPaths): void {
                foreach ($trashedPaths as $trashedPath) {
                    $query->where('path', 'not like', $trashedPath.'%');
                }
            })
            ->pluck('id')
            ->all();
    }

    /**
     * True when some directory strictly above this one -- never this
     * directory itself -- is trashed.
     */
    private function hasTrashedProperAncestor(Directory $directory): bool
    {
        foreach ($this->trashedPaths() as $trashedPath) {
            if ($trashedPath !== $directory->path && str_starts_with($directory->path, $trashedPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every trashed directory's path, memoised per request: this rule would
     * otherwise cost one query per directory checked instead of one query
     * total. See flush().
     *
     * @return array<int, string>
     */
    private function trashedPaths(): array
    {
        return $this->trashedPaths ??= Directory::onlyTrashed()->pluck('path')->all();
    }

    /**
     * Drop the memoised answers.
     *
     * The memoisation above is only safe while nothing has changed what a grant
     * means. Because this service is a singleton, a caller that resolves access,
     * then writes a grant, then resolves again would otherwise get the stale
     * answer -- a correctness hole in the one component the whole authorisation
     * story rests on. Invalidation is wired to grant writes AND to directory
     * writes, because subtree membership is derived from the materialised path,
     * so moving a directory changes who can reach it.
     */
    public function flush(): void
    {
        $this->levels = [];
        $this->viewable = [];
        $this->trashedPaths = null;
    }

    private function grantsFor(User $user): Builder
    {
        $roleIds = $user->roles->pluck('id')->all();

        return DirectoryGrant::query()->where(function (Builder $query) use ($user, $roleIds): void {
            $query->where(fn (Builder $q) => $q->where('grantee_type', 'user')->where('grantee_id', $user->getKey()));

            if ($roleIds !== []) {
                $query->orWhere(fn (Builder $q) => $q->where('grantee_type', 'role')->whereIn('grantee_id', $roleIds));
            }
        });
    }
}

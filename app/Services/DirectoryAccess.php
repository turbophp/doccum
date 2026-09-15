<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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

    private function resolve(User $user, Directory $directory): ?AccessLevel
    {
        if ($user->can('directories.view-all')) {
            return AccessLevel::Manage;
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

        return Directory::query()
            ->where(function (Builder $query) use ($paths): void {
                foreach ($paths as $path) {
                    $query->orWhere('path', 'like', $path.'%');
                }
            })
            ->pluck('id')
            ->all();
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

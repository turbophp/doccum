<?php

declare(strict_types=1);

namespace App\Livewire\Files;

use App\Models\Directory;
use App\Services\DirectoryAccess;
use App\Services\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * The directory tree pane (design plan §8: "TreePane" + "TreeNode").
 *
 * Flux ships no tree component, so this is hand-built: a single Blade view
 * that includes itself recursively, one level of children queried at a time.
 * A directory ten levels deep and fully collapsed never has its children
 * queried at all -- that is what "lazily loads children" means here.
 *
 * The one rule that must never be worked around: every listing is filtered
 * through DirectoryAccess::viewableDirectoryIds() in the query, never in the
 * view (CLAUDE.md's seam). That resolver expands a grant DOWNWARD into its
 * descendants, which on its own is not enough for a tree: a grant three
 * levels deep would leave its own ancestors unlisted, and an unlisted
 * ancestor has no row to expand, so the grant would be unreachable. See
 * reachableIds() below for the fix -- it is the subtlest part of this
 * component and the one thing a change here must not regress.
 */
class Tree extends Component
{
    /** @var array<int, bool> Expanded directory ids, keyed for O(1) lookup. */
    public array $expanded = [];

    /** Whether the "Shared" section (everything but the viewer's own home) is expanded. */
    public bool $sharedExpanded = true;

    public ?int $selectedId = null;

    /** @var array<int, int>|null Memoised for the lifetime of one request. */
    private ?array $reachable = null;

    public function mount(): void
    {
        $state = app(Settings::class)->get($this->settingsKey(), []);

        $this->expanded = is_array($state['expanded'] ?? null) ? $state['expanded'] : [];
        $this->sharedExpanded = (bool) ($state['shared'] ?? true);
    }

    /**
     * Expand or collapse one node. Persisted immediately, so the state a
     * viewer left the tree in is exactly the state it opens in next time.
     */
    public function toggle(int $directoryId): void
    {
        if (isset($this->expanded[$directoryId])) {
            unset($this->expanded[$directoryId]);
        } else {
            $this->expanded[$directoryId] = true;
        }

        $this->persist();
    }

    public function toggleShared(): void
    {
        $this->sharedExpanded = ! $this->sharedExpanded;

        $this->persist();
    }

    /**
     * Selecting a node navigates the list pane. This component only ever
     * announces the selection -- it never calls into a list component
     * directly, so the tree and the list stay decoupled.
     */
    public function select(int $directoryId): void
    {
        $this->selectedId = $directoryId;

        $this->dispatch('directory-selected', directoryId: $directoryId);
    }

    /**
     * One level of a directory's children (root, when $parentId is null),
     * filtered to what this viewer may reach. Called directly from the view
     * so that a collapsed node's children are never fetched.
     *
     * @return Collection<int, Directory>
     */
    public function childrenOf(?int $parentId): Collection
    {
        $reachable = $this->reachableIds();

        if ($reachable === []) {
            return new Collection;
        }

        return Directory::query()
            ->where('parent_id', $parentId)
            ->whereIn('id', $reachable)
            ->orderBy('name')
            ->get();
    }

    /**
     * Every directory id this viewer may see in the tree: what
     * DirectoryAccess grants (a directory and its descendants), plus every
     * ancestor of each of those -- so the path back to a deep grant always
     * resolves, while a sibling branch with no grant of its own, and no
     * viewable descendant, stays invisible.
     *
     * @return array<int, int>
     */
    private function reachableIds(): array
    {
        if ($this->reachable !== null) {
            return $this->reachable;
        }

        $viewable = app(DirectoryAccess::class)->viewableDirectoryIds(auth()->user());

        if ($viewable === []) {
            return $this->reachable = [];
        }

        $ancestorIds = Directory::query()
            ->whereIn('id', $viewable)
            ->pluck('path')
            ->flatMap(static fn (string $path): array => array_map(
                'intval',
                array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''),
            ))
            ->all();

        return $this->reachable = array_values(array_unique([...$viewable, ...$ancestorIds]));
    }

    private function settingsKey(): string
    {
        return 'shell.tree.'.auth()->id();
    }

    private function persist(): void
    {
        $userId = auth()->id();

        app(Settings::class)->set($this->settingsKey(), [
            'expanded' => $this->expanded,
            'shared' => $this->sharedExpanded,
        ], is_int($userId) ? $userId : null);
    }

    public function render(): View
    {
        $user = auth()->user();
        $home = Directory::query()->where('home_user_id', $user->getKey())->first();

        return view('livewire.files.tree', [
            'home' => $home,
            'roots' => $this->childrenOf(null)->reject(
                static fn (Directory $directory): bool => $home !== null && $directory->is($home),
            ),
        ]);
    }
}

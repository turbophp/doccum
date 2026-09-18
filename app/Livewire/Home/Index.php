<?php

declare(strict_types=1);

namespace App\Livewire\Home;

use App\Models\File;
use App\Services\DirectoryAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * item/home-dashboard (issue #16): the Home destination spec §10 names --
 * "recent files, recent activity, storage consumed by period, quick search
 * entry" -- narrowed to what this item's doneWhen actually asks for.
 *
 * Built here: recent files, filtered through DirectoryAccess exactly like
 * every other listing in this app, and a quick entry into search.
 *
 * Deliberately NOT built here, per the item's own scope note ("if a part
 * needs its own query design or a new aggregate, do not build it"):
 *
 * - "Recent activity" -- there is no activity/audit log in this codebase at
 *   all (no spatie/laravel-activitylog, nothing under app/Models tracking
 *   who did what). Building one is its own item, not a query this render()
 *   can grow.
 * - "Storage consumed by period" -- needs a bytes-per-period aggregate
 *   nothing here already computes (doccum:purge-period reports bytes for
 *   ONE period on demand; a dashboard widget wants many periods at once,
 *   which is a different query shape). See the report for the proposed
 *   follow-up items for both.
 *
 * The one thing this render() MUST get right, per the doneWhen: recents are
 * filtered IN THE QUERY through DirectoryAccess -- never a Blade @if, never
 * a collection filter after the fact -- and the page renders correctly for
 * a viewer with no reachable directories at all (a brand-new user, before
 * CreateHomeDirectory's own grant even exists): $recentFiles is an empty
 * Collection, not a query error, whenever $viewable is [].
 */
#[Layout('layouts::app')]
class Index extends Component
{
    /** How many recent files to show. Arbitrary but small -- this is a dashboard widget, not a listing. */
    private const RECENT_LIMIT = 10;

    public function render(): View
    {
        $user = auth()->user();
        $access = app(DirectoryAccess::class);

        // The SAME method Browser::render() and Trash\Index::render() both
        // resolve reach through (CLAUDE.md's DirectoryAccess seam) -- never
        // a fresh per-file check here.
        $viewable = $access->viewableDirectoryIds($user);

        // Written out explicitly rather than handed straight to
        // whereIn('directory_id', $viewable) and trusted to "just work":
        // an empty whereIn() is a legitimate, correct "match nothing" SQL
        // query, but this branch exists so that fact is a documented
        // decision here rather than an accident of the query builder, for
        // exactly the case the doneWhen names -- a viewer with zero reach.
        //
        // File::query()'s own default scope (SoftDeletes) is what keeps a
        // TRASHED file out of this list -- not an extra ->whereNull
        // ('deleted_at') here. A file trashed independently, or one
        // cascaded alongside a trashed directory, is excluded by that scope
        // either way, so there is nothing this query needs to add for it.
        $recentFiles = $viewable === []
            ? new Collection
            : File::query()
                ->whereIn('directory_id', $viewable)
                ->with(['creator', 'directory'])
                ->orderByDesc('updated_at')
                ->limit(self::RECENT_LIMIT)
                ->get();

        return view('livewire.home.index', [
            'recentFiles' => $recentFiles,
        ]);
    }
}

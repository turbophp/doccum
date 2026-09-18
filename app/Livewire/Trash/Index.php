<?php

declare(strict_types=1);

namespace App\Livewire\Trash;

use App\Actions\Directories\RestoreDirectory;
use App\Actions\Files\PurgeFile;
use App\Actions\Files\RestoreFile;
use App\Exceptions\DuplicateDirectoryName;
use App\Exceptions\DuplicateFileName;
use App\Exceptions\FileUnderLegalHold;
use App\Exceptions\PeriodIsArchived;
use App\Models\Directory;
use App\Models\File;
use App\Services\DirectoryAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * item/trash-view (issue #15): lists what the signed-in viewer may see of
 * the trash, and nothing else, with Restore and Purge wired straight to the
 * existing Actions.
 *
 * The doneWhen is deliberately about the QUERY, not the view: "another
 * user's trashed items never appear, asserted at query level". render()
 * below is the only place that decides what is in $files and $directories
 * -- both narrowed with whereIn() against ids DirectoryAccess resolved,
 * exactly the shape Browser::render() already uses for the live tree
 * (CLAUDE.md: filter in the query, never the view). There is no directory
 * purge here on purpose -- PurgeFile's own docblock explains why a whole
 * subtree cannot safely be purged by one action yet, and RestoreDirectory
 * is the only Directory action this page calls.
 *
 * Every write method below authorises HERE, through a Policy, exactly once,
 * and trusts the Policy for both layers -- the same authorise-then-act
 * shape as every method in Browser (CLAUDE.md: actions never authorise).
 * None of RestoreFile, PurgeFile or RestoreDirectory checks anything on its
 * own.
 */
#[Layout('layouts::app')]
class Index extends Component
{
    /**
     * The most recent action's refusal, shown once at the top of the page.
     * A list page has no single row to attach a validation error to the
     * way Browser's detail panel does, so this is a plain flash-style
     * property instead of $this->addError().
     */
    public ?string $error = null;

    public function restoreFile(int $fileId, RestoreFile $action): void
    {
        $this->error = null;

        // find(), not findOrFail(): a Livewire component test never sees
        // ModelNotFoundException as a 404 (it propagates instead), which is
        // exactly the trap CLAUDE.md warns about here. abort_if() is the
        // refusal this codebase uses everywhere else for the same reason
        // (see Browser::revokeAccess()).
        $file = File::onlyTrashed()->find($fileId);

        abort_if($file === null, 404);

        $this->authorize('restore', $file);

        try {
            $action->handle($file);
        } catch (DuplicateFileName|PeriodIsArchived $e) {
            $this->error = $e->getMessage();
        }
    }

    public function purgeFile(int $fileId, PurgeFile $action): void
    {
        $this->error = null;

        $file = File::onlyTrashed()->find($fileId);

        abort_if($file === null, 404);

        $this->authorize('purge', $file);

        try {
            $action->handle($file);
        } catch (FileUnderLegalHold|PeriodIsArchived $e) {
            $this->error = $e->getMessage();
        }
    }

    public function restoreDirectory(int $directoryId, RestoreDirectory $action): void
    {
        $this->error = null;

        $directory = Directory::onlyTrashed()->find($directoryId);

        abort_if($directory === null, 404);

        $this->authorize('restore', $directory);

        try {
            $action->handle($directory);
        } catch (DuplicateDirectoryName $e) {
            $this->error = $e->getMessage();
        }
    }

    // Typed, unlike Browser::render() and two others beside it. Those three
    // are pre-existing phpstan-baseline.neon entries; CLAUDE.md reserves the
    // baseline for exactly that and says to add none, so a new component
    // declares what it returns rather than inheriting an exemption.
    //
    // The CONTRACT, Illuminate\Contracts\View\View, matching both components
    // here that declare it -- view() returns the contract, so the concrete
    // Illuminate\View\View would be a different phpstan error a round later.
    public function render(): View
    {
        $user = auth()->user();
        $access = app(DirectoryAccess::class);

        // The trashed half of the reach: DirectoryAccess::viewableTrashedDirectoryIds()
        // is the ONLY place that decides which trashed directories a viewer
        // may know about (CLAUDE.md's DirectoryAccess seam) -- resolved
        // through the same can() check restore()/purge() already use, so
        // this list can never grant something the Policy would refuse.
        $trashedDirectoryIds = $access->viewableTrashedDirectoryIds($user);

        // A trashed FILE's directory may be live (trashed independently of
        // it) or itself trashed (cascaded alongside it) -- so the set a
        // file is checked against is the union of both halves of reach,
        // never viewableDirectoryIds() alone.
        $reachableDirectoryIds = array_values(array_unique([
            ...$access->viewableDirectoryIds($user),
            ...$trashedDirectoryIds,
        ]));

        // Filtered by the resolver, never by the view: a listing that
        // forgets this leaks the existence of another user's trashed file.
        $files = File::onlyTrashed()
            ->whereIn('directory_id', $reachableDirectoryIds)
            ->with('creator')
            ->orderByDesc('deleted_at')
            ->get();

        // Same filtering, narrowed to the trashed-only half of reach --
        // viewableTrashedDirectoryIds() already excludes a directory nested
        // under a still-trashed ancestor for anyone but a directories.view-all
        // holder, so nothing further is needed here.
        $directories = Directory::onlyTrashed()
            ->whereIn('id', $trashedDirectoryIds)
            ->orderByDesc('deleted_at')
            ->get();

        // Names for the directory each trashed file lived in, fetched
        // withTrashed() and separately from the eager load above: File::directory()
        // is an ordinary belongsTo, which resolves through Directory's
        // default, non-trashed scope, so it would come back null for any
        // file whose directory was trashed alongside it.
        $directoryNames = Directory::withTrashed()
            ->whereIn('id', $files->pluck('directory_id')->unique())
            ->pluck('name', 'id');

        return view('livewire.trash.index', [
            'files' => $files,
            'directories' => $directories,
            'directoryNames' => $directoryNames,
        ]);
    }
}

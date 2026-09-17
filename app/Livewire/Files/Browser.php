<?php

declare(strict_types=1);

namespace App\Livewire\Files;

use App\Actions\Directories\CreateDirectory;
use App\Actions\Directories\MoveDirectory;
use App\Actions\Directories\RenameDirectory;
use App\Actions\Directories\TrashDirectory;
use App\Actions\Files\MoveFile;
use App\Actions\Files\RenameFile;
use App\Actions\Files\SetLegalHold;
use App\Actions\Files\StoreFileVersion;
use App\Actions\Files\TrashFile;
use App\Enums\AccessLevel;
use App\Exceptions\CannotMoveDirectoryIntoItself;
use App\Exceptions\DuplicateDirectoryName;
use App\Exceptions\DuplicateFileName;
use App\Exceptions\PeriodIsArchived;
use App\Models\Directory;
use App\Models\File;
use App\Models\FileVersion;
use App\Services\DirectoryAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A deliberately minimal permission-filtered browser: list, create a
 * subdirectory, upload, select a row and act on it. The full three-pane
 * Dropbox shell is a later plan.
 *
 * What must hold here is that it never lists or opens a directory or file the
 * viewer cannot reach -- filtering happens in the query, never in the view --
 * and that every action below is authorised HERE, through a Policy, never
 * inside the Action it calls (CLAUDE.md: actions never authorise). Two
 * independent layers gate each one -- a Spatie permission and
 * directory_access -- and both are the Policy's job to check, not this
 * component's: every method below authorises with a single `$this
 * ->authorize(...)` call and trusts the Policy for the rest.
 */
#[Layout('layouts::app')]
class Browser extends Component
{
    use WithFileUploads;

    public ?Directory $directory = null;

    public ?File $selectedFile = null;

    public ?Directory $selectedDirectory = null;

    public string $newDirectoryName = '';

    public $upload;

    /**
     * Deliberately its OWN property, never reused from $upload. With one
     * shared property, choosing a file to replace and then clicking the
     * main Upload button would post that same file through store() --
     * creating a second file rather than a new version of the selected
     * one -- which is the exact bug this item's mutation is about, and it
     * is reachable by an ordinary user, not just a test double.
     *
     * Left untyped for the same reason $upload above is: across the upload
     * lifecycle WithFileUploads assigns this a string, then an array, then
     * a TemporaryUploadedFile, so a native property type would reject a
     * value Livewire itself sets. $upload predates phpstan-baseline.neon and
     * sits in it; this one carries an annotation instead, because CLAUDE.md
     * says to add no new findings and a new baseline entry is a finding with
     * the alarm switched off rather than one fixed.
     *
     * @var mixed
     */
    public $replacement;

    /** Shared by renameFile() and renameDirectory() -- only one of the two subjects is ever selected at a time. */
    public string $renameValue = '';

    public ?int $moveFileDestinationId = null;

    /** '' means "the root" -- MoveDirectory accepts a null destination, and a <select> option cannot carry null directly. */
    public string $moveDirectoryDestinationId = '';

    /**
     * The column a header click sorts by, resolved through SORTABLE below --
     * NEVER interpolated into orderBy() directly, which is how an
     * unwhitelisted column becomes SQL injection (issue #103's own note).
     * An unknown value falls back to 'name'; see resolveSortColumn().
     */
    public string $sort = 'name';

    /** An unknown value falls back to 'asc'; see resolveSortDirection(). */
    public string $direction = 'asc';

    /**
     * Every id currently ticked in the files table, independent of which
     * (if any) row's detail panel is open -- $selectedFile/$selectedDirectory
     * above are about the property panel, this is about bulkTrash()'s
     * target set. See selectRow() and bulkTrash().
     *
     * @var array<int, int>
     */
    public array $selectedIds = [];

    /**
     * The anchor for a shift-click range -- the id from the last PLAIN or
     * ctrl click, deliberately left untouched BY a shift-click itself. See
     * selectRow()'s docblock for why.
     */
    public ?int $lastClickedId = null;

    /**
     * Maps a sort key the view can pass to sortBy()/wire:click to the
     * actual column orderBy() sees. This is the whitelist: resolveSortColumn()
     * below is the only thing that may ever read it, and nothing else may
     * hand a raw $sort value to the query builder.
     *
     * 'owner' orders by the joined users.name -- see the leftJoin in
     * filesQuery() -- never by files.created_by, which would sort by
     * whichever integer ids happened to be assigned rather than by name.
     *
     * @var array<string, string>
     */
    private const SORTABLE = [
        'name' => 'files.name',
        'owner' => 'users.name',
        'modified' => 'files.updated_at',
        'size' => 'files.size',
    ];

    public function mount(?Directory $directory = null): void
    {
        if ($directory !== null) {
            $this->authorize('view', $directory);
        }

        $this->directory = $directory;
    }

    /** Opens the detail area's property panel on one file in the current directory. */
    public function selectFile(int $fileId): void
    {
        $file = File::query()->where('directory_id', $this->directory?->getKey())->findOrFail($fileId);

        $this->authorize('view', $file);

        $this->selectedDirectory = null;
        $this->selectedFile = $file;
        $this->renameValue = $file->name;
        $this->moveFileDestinationId = null;
    }

    /** Opens the detail area's property panel on one subdirectory of the current directory. */
    public function selectDirectory(int $directoryId): void
    {
        $subdirectory = Directory::query()->where('parent_id', $this->directory?->getKey())->findOrFail($directoryId);

        // Deliberately NOT in .github/mutations.json, and the reason is worth
        // stating rather than leaving as an omission someone later "fixes".
        // Removing this line does not change any observable outcome: the panel
        // it opens renders <livewire:files.property-panel>, whose own mount()
        // authorises view on the same subject, so an unviewable directory is
        // refused either way and the mutation harness correctly reported the
        // guard as not load-bearing. It stays because PropertyPanel refusing
        // is PropertyPanel's concern, not this component's, and this is the
        // line that keeps being true if that ever changes -- but a
        // mutations.json entry for it would claim a proof that does not
        // exist, which CLAUDE.md is explicit about. See issue #101.
        $this->authorize('view', $subdirectory);

        $this->selectedFile = null;
        $this->selectedDirectory = $subdirectory;
        $this->renameValue = $subdirectory->name;
        $this->moveDirectoryDestinationId = '';
    }

    public function createDirectory(CreateDirectory $action): void
    {
        $this->validate(['newDirectoryName' => ['required', 'string', 'max:255']]);

        if ($this->directory !== null) {
            $this->authorize('create', $this->directory);
        }

        try {
            $action->handle(auth()->user(), $this->newDirectoryName, $this->directory);
        } catch (DuplicateDirectoryName $e) {
            $this->addError('newDirectoryName', $e->getMessage());

            return;
        }

        $this->newDirectoryName = '';
    }

    public function store(StoreFileVersion $action): void
    {
        abort_if($this->directory === null, 422, 'Choose a directory before uploading.');

        $this->authorize('create', [File::class, $this->directory]);
        $this->validate(['upload' => ['required', 'file', 'max:102400']]);

        $action->handle(
            auth()->user(),
            $this->directory,
            $this->upload->getRealPath(),
            $this->upload->getClientOriginalName(),
            $this->upload->getMimeType(),
        );

        $this->upload = null;
    }

    /**
     * Uploads a new version under the selected file's own name, rather than
     * creating a second file. See FilePolicy::replace() for why this is
     * gated as an upload into the file's directory plus a check on the
     * file's own trashed state, and see the $replacement property above for
     * why this never touches $upload.
     */
    public function replaceFile(StoreFileVersion $action): void
    {
        abort_if($this->selectedFile === null, 404);

        $this->authorize('replace', $this->selectedFile);

        $this->validate(['replacement' => ['required', 'file', 'max:102400']]);

        // Resolved from the FILE, never from $this->directory. After
        // moveFile() the selected file lives somewhere else while
        // $this->directory is unchanged, and handing StoreFileVersion the
        // browsed directory would make its (directory_id, name_key) lookup
        // miss and CREATE A NEW FILE in the wrong directory -- the create
        // path, with no mutation needed.
        $directory = Directory::query()->findOrFail($this->selectedFile->directory_id);

        try {
            $this->selectedFile = $action->handle(
                auth()->user(),
                $directory,
                $this->replacement->getRealPath(),
                // The selected file's OWN name, never the uploaded file's
                // client name. This single argument is what makes Replace
                // append a version instead of creating a second file, and it
                // is also what keeps v2's object key named after the
                // document rather than after whatever the operator happened
                // to call the new upload (see App\Support\ObjectKey). It is
                // the line the container smoke's mutation flips.
                $this->selectedFile->name,
                $this->replacement->getMimeType(),
            );
        } catch (PeriodIsArchived $e) {
            $this->addError('replacement', $e->getMessage());

            return;
        }

        $this->replacement = null;
    }

    public function renameFile(RenameFile $action): void
    {
        abort_if($this->selectedFile === null, 404);

        $this->authorize('update', $this->selectedFile);

        $this->validate(['renameValue' => ['required', 'string', 'max:255']]);

        try {
            $this->selectedFile = $action->handle($this->selectedFile, $this->renameValue);
        } catch (DuplicateFileName $e) {
            $this->addError('renameValue', $e->getMessage());
        }
    }

    public function moveFile(MoveFile $action): void
    {
        abort_if($this->selectedFile === null, 404);

        $this->validate(['moveFileDestinationId' => ['required', 'integer']]);

        $destination = Directory::query()->findOrFail($this->moveFileDestinationId);

        $this->authorize('move', [$this->selectedFile, $destination]);

        try {
            // Refreshed from whichever directory it lands in -- render()
            // re-queries $files scoped to $this->directory, so a file moved
            // elsewhere simply stops appearing in the listing being browsed,
            // exactly like TrashFile below.
            $this->selectedFile = $action->handle($this->selectedFile, $destination);
        } catch (DuplicateFileName $e) {
            $this->addError('moveFileDestinationId', $e->getMessage());
        }
    }

    public function trashFile(TrashFile $action): void
    {
        abort_if($this->selectedFile === null, 404);

        $this->authorize('delete', $this->selectedFile);

        $action->handle($this->selectedFile);

        $this->selectedFile = null;
    }

    public function setLegalHold(bool $hold, SetLegalHold $action): void
    {
        abort_if($this->selectedFile === null, 404);

        $this->authorize('legalHold', $this->selectedFile);

        $this->selectedFile = $action->handle($this->selectedFile, $hold);
    }

    public function renameDirectory(RenameDirectory $action): void
    {
        abort_if($this->selectedDirectory === null, 404);

        $this->authorize('update', $this->selectedDirectory);

        $this->validate(['renameValue' => ['required', 'string', 'max:255']]);

        try {
            $this->selectedDirectory = $action->handle($this->selectedDirectory, $this->renameValue);
        } catch (DuplicateDirectoryName $e) {
            $this->addError('renameValue', $e->getMessage());
        }
    }

    public function moveDirectory(MoveDirectory $action): void
    {
        abort_if($this->selectedDirectory === null, 404);

        $destination = $this->moveDirectoryDestinationId === ''
            ? null
            : Directory::query()->findOrFail((int) $this->moveDirectoryDestinationId);

        $this->authorize('move', [$this->selectedDirectory, $destination]);

        try {
            $this->selectedDirectory = $action->handle($this->selectedDirectory, $destination);
        } catch (DuplicateDirectoryName|CannotMoveDirectoryIntoItself $e) {
            $this->addError('moveDirectoryDestinationId', $e->getMessage());
        }
    }

    public function trashDirectory(TrashDirectory $action): void
    {
        abort_if($this->selectedDirectory === null, 404);

        $this->authorize('delete', $this->selectedDirectory);

        $action->handle($this->selectedDirectory);

        $this->selectedDirectory = null;
    }

    /**
     * A header cell click. A second click on the SAME column flips
     * direction (the common "click again to reverse" convention); a click
     * on a DIFFERENT column switches to it, always starting ascending --
     * whatever the previous column's direction was carries no meaning for
     * a column nothing has been sorted by yet.
     *
     * $column is not validated against SORTABLE here -- there is nothing to
     * protect yet, since $sort is only ever turned into a query column
     * inside filesQuery() via resolveSortColumn(), which is the one place
     * that whitelist has to be enforced. An absurd value typed into
     * ->set('sort', ...) directly (bypassing this method entirely, as the
     * fallback test does) reaches that same resolution, so the guard lives
     * there rather than being duplicated here too.
     */
    public function sortBy(string $column): void
    {
        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = 'asc';
        }
    }

    /**
     * The row-click handler behind multi-select. Livewire's own wire:click
     * has no access to the DOM event, so the Blade template drives this
     * through Alpine instead, reading the real click's modifier keys and
     * calling $wire.selectRow(id, $event.shiftKey, $event.ctrlKey ||
     * $event.metaKey) -- see the table row in the view.
     *
     * - plain click: replace the selection with just this row.
     * - ctrl/cmd-click: toggle this row, leaving the rest of the selection
     *   alone.
     * - shift-click: select the INCLUSIVE range between $lastClickedId (the
     *   anchor) and this row, computed over the id list the query
     *   currently produces -- i.e. in whatever order the table is
     *   presently sorted, never raw id order. See rangeBetween().
     *
     * $lastClickedId is deliberately left unmoved by a shift-click itself
     * -- only a plain or ctrl click ever moves the anchor -- so a second,
     * further shift-click extends or shrinks the range from the ORIGINAL
     * anchor, the same as a spreadsheet or file manager, rather than from
     * wherever the previous shift-click happened to land.
     */
    public function selectRow(int $id, bool $shift, bool $ctrl): void
    {
        if ($shift && $this->lastClickedId !== null) {
            $this->selectedIds = $this->rangeBetween($this->lastClickedId, $id);

            return;
        }

        if ($ctrl) {
            $this->selectedIds = in_array($id, $this->selectedIds, true)
                ? array_values(array_diff($this->selectedIds, [$id]))
                : [...$this->selectedIds, $id];
        } else {
            $this->selectedIds = [$id];
        }

        $this->lastClickedId = $id;
    }

    /**
     * Bulk-trashes every currently selected file. This is the security core
     * of this item (issue #103): CLAUDE.md is explicit that Actions never
     * authorise -- TrashFile::handle() below trashes unconditionally, the
     * same as it always has -- so THIS method is the only thing standing
     * between a manipulated $selectedIds and every file in the instance
     * being trashed by whoever can reach this component at all.
     *
     * Two passes, deliberately never merged into one loop:
     *
     * 1. Authorise EVERY selected file first, through FilePolicy::delete()
     *    -- the same policy method, and the same two independent layers
     *    (files.delete, directory_access), that trashFile() above already
     *    uses for a single file. authorize() throws on the first refusal,
     *    which is exactly the point: if even one id in the selection is
     *    refused, execution never reaches pass 2 and NOTHING has been
     *    trashed yet, for any of them. A single combined
     *    authorise-then-trash loop would not give this guarantee -- it
     *    would trash every id it happened to reach before the refused one,
     *    then throw with those already gone.
     * 2. Only once every one of them has cleared pass 1 does pass 2 trash
     *    them.
     *
     * Resolved by id alone -- File::whereIn('id', ...), not additionally
     * filtered by $this->directory -- on purpose. In ordinary use
     * $selectedIds only ever contains ids selectRow() offered from the
     * directory currently being rendered, so that filter would be
     * redundant there; but FilePolicy::delete() already resolves and
     * checks each file's OWN directory, whichever directory that
     * genuinely is, so it is the authorisation pass above that has to
     * answer for an id outside the browsed directory, not a second,
     * narrower query here quietly excluding it beforehand and making the
     * authorize() call look load-bearing when it was never reached.
     */
    public function bulkTrash(TrashFile $action): void
    {
        abort_if($this->directory === null, 404);

        // Scoped to the directory being browsed, exactly as selectFile()
        // scopes its own lookup and for the same reason: $selectedIds
        // arrives from the client, and the listing this component renders
        // only ever contains files from this directory, so an id from
        // anywhere else did not come from the UI.
        $files = File::query()
            ->where('directory_id', $this->directory->getKey())
            ->get();

        // Every selected id must resolve, or nothing happens at all.
        // Without this, an id that resolves to nothing -- crafted, or a file
        // someone else trashed or moved since the listing rendered -- is
        // silently dropped by whereIn(), and the method trashes a SUBSET of
        // the selection while reporting success. "Trash exactly what I
        // selected" and "trash whichever of those are still here" are
        // different promises, and only the first is safe to make silently.
        // Two passes, deliberately. Authorising and trashing in a single
        // loop would leave every file before the refusal already trashed,
        // which satisfies "trashes nothing if any one is refused" in wording
        // only.
        //
        // What that structure can and cannot be shown to do is worth stating
        // plainly, because the mutation entry deliberately claims less than
        // the backlog item's wording does. FilePolicy::delete() is
        // files.delete -- a global permission -- plus
        // DirectoryAccess::can(Edit) on the file's directory, and nothing
        // per-file. Every file resolved above is in the SAME directory, so
        // delete() answers identically for all of them: the refusal is
        // all-or-nothing today, and a case where exactly one of several is
        // refused cannot be constructed. The two passes are therefore
        // defensive rather than demonstrable, and worth keeping for when
        // delete() grows a per-file condition -- PurgeFile already refuses a
        // file under legal hold and trash plausibly should too. The mutation
        // entry claims only what is provable: delete the authorize pass and
        // a viewer holding view alone trashes every file in the directory.
        foreach ($files as $file) {
            $this->authorize('delete', $file);
        }

        foreach ($files as $file) {
            $action->handle($file);
        }

        $this->selectedIds = [];
    }

    public function render()
    {
        $access = app(DirectoryAccess::class);
        $viewable = $access->viewableDirectoryIds(auth()->user());

        return view('livewire.files.browser', [
            // Filtered by the resolver, never by the view: a listing that
            // forgets this leaks the existence of directories.
            'directories' => Directory::query()
                ->where('parent_id', $this->directory?->getKey())
                ->whereIn('id', $viewable)
                ->orderBy('name')
                ->get(),
            // filesQuery() carries the join + whitelisted orderBy this
            // listing sorts by; ->with('creator') alongside it eager-loads
            // the owner column so the view never queries per row -- the
            // leftJoin filesQuery() applies exists to sort by it, not to
            // fetch it, which is why this is a SEPARATE eager load rather
            // than reading $item->created_by off the joined row.
            'files' => $this->directory === null
                ? collect()
                : $this->filesQuery()->with('creator')->get(),
            // Both destination lists are built from $viewable -- resolved by
            // DirectoryAccess::viewableDirectoryIds() above and applied with
            // whereIn() right here in the query -- never filtered down in the
            // Blade template, which would first hand the view every
            // directory in the instance.
            'moveFileDestinations' => $this->selectedFile === null
                ? new Collection
                : $this->editableDestinations($access, $viewable),
            'moveDirectoryDestinations' => $this->selectedDirectory === null
                ? new Collection
                : $this->editableDestinations($access, $viewable),
            // Queried fresh here rather than through $selectedFile->versions,
            // a relation that may have been loaded earlier in the request
            // (e.g. before replaceFile() added one) and would then render
            // stale. Ordered by version_number -- the domain key
            // StoreFileVersion computes -- rather than created_at (ties at
            // second resolution) or id (an implementation detail that only
            // happens to agree with it today).
            'versions' => $this->selectedFile === null
                ? new Collection
                : FileVersion::query()
                    ->where('file_id', $this->selectedFile->getKey())
                    ->with('uploader')
                    ->orderByDesc('version_number')
                    ->get(),
        ]);
    }

    /**
     * Every directory the viewer may at least view, narrowed to the ones
     * they hold edit access on -- the level FilePolicy::move() and
     * DirectoryPolicy::move() both require on a destination. Listing a
     * directory here that the confirming authorize() call would refuse
     * anyway would be a dead end dressed up as a choice.
     *
     * @param  array<int, int>  $viewableIds
     * @return Collection<int, Directory>
     */
    private function editableDestinations(DirectoryAccess $access, array $viewableIds): Collection
    {
        $user = auth()->user();

        return Directory::query()
            ->whereIn('id', $viewableIds)
            ->orderBy('name')
            ->get()
            ->filter(fn (Directory $candidate): bool => $access->can($user, $candidate, AccessLevel::Edit))
            ->values();
    }

    /**
     * The files table's query: the currently browsed directory, sorted by
     * the whitelisted column resolveSortColumn() resolves $sort to. Callers
     * decide what to eager-load and how many rows to take.
     *
     * The leftJoin is applied UNCONDITIONALLY, not only when sorting by
     * owner: render() always renders an owner column regardless of $sort,
     * this is the one query both render() and rangeBetween()/sortedFileIds()
     * below share, and branching its shape by $sort would mean two
     * different id orderings existing in the codebase for the "same"
     * query. ->select('files.*') is what keeps that join from leaking
     * users.id/users.name into the hydrated File models -- without it a
     * File whose creator shares no columns with `users` would still come
     * out fine, but here every file's creator is a real row, so the
     * join's columns silently win and $item->id becomes the OWNER's id.
     *
     * @return Builder<File>
     */
    private function filesQuery(): Builder
    {
        abort_if($this->directory === null, 404);

        return File::query()
            ->leftJoin('users', 'files.created_by', '=', 'users.id')
            ->where('files.directory_id', $this->directory->getKey())
            ->select('files.*')
            ->orderBy($this->resolveSortColumn(), $this->resolveSortDirection());
    }

    /**
     * $sort resolved against the SORTABLE whitelist, falling back to
     * 'name' for anything that is not one of its keys -- including a value
     * set directly (bypassing sortBy() entirely, which is how the
     * fallback test proves this rather than sortBy()'s own behaviour).
     * This, not sortBy(), is the ONE place a column reaches orderBy(), so
     * it is the one place that has to enforce the whitelist.
     */
    private function resolveSortColumn(): string
    {
        return self::SORTABLE[$this->sort] ?? self::SORTABLE['name'];
    }

    /** $direction resolved to a literal 'asc'/'desc', falling back to 'asc' for anything else. */
    /**
     * Narrowed to the two literals rather than plain string, because
     * Builder::orderBy() is typed 'asc'|'desc'|SortDirection and a bare
     * string is not assignable to it. The annotation is not decoration: it
     * is the type this method has always actually returned, and writing it
     * down is what lets phpstan check the call site instead of adding
     * another entry to the baseline.
     *
     * @return 'asc'|'desc'
     */
    private function resolveSortDirection(): string
    {
        return $this->direction === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Every file id in the directory currently being browsed, in the
     * CURRENT sort order -- the order selectRow()'s shift-range is defined
     * over. Deliberately re-derived from filesQuery() rather than reusing
     * whatever $files render() last handed the view: that Collection is
     * built once per request and this can be called from selectRow(),
     * a wholly separate Livewire action call with no render() in between.
     *
     * @return array<int, int>
     */
    private function sortedFileIds(): array
    {
        if ($this->directory === null) {
            return [];
        }

        // Cast explicitly rather than trust pluck() to hand back int: it
        // reads the raw column through the query builder, not through
        // Eloquent's own attribute casting, so what a driver returns for an
        // integer column is the driver's choice, not this model's.
        return $this->filesQuery()
            ->pluck('files.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * The inclusive range between the shift-click anchor and the row just
     * clicked, computed over sortedFileIds() -- i.e. over the id list in
     * whatever order the table is PRESENTLY sorted, never over the two
     * ids' own numeric values. A range computed from min($anchorId,
     * $targetId) to max($anchorId, $targetId) would silently be correct
     * only by coincidence whenever sort order and id order happen to
     * agree, and wrong the moment they do not -- which is exactly what
     * FileBrowserListTest's shift-range test sorts descending to prove.
     *
     * If either endpoint is no longer part of the current listing (moved,
     * trashed, or simply stale after a sort or directory change since
     * $lastClickedId was set), this falls back to selecting just the row
     * that was actually clicked rather than guessing at a range that no
     * longer means anything.
     *
     * @return array<int, int>
     */
    private function rangeBetween(int $anchorId, int $targetId): array
    {
        $ids = $this->sortedFileIds();

        $anchorIndex = array_search($anchorId, $ids, true);
        $targetIndex = array_search($targetId, $ids, true);

        if ($anchorIndex === false || $targetIndex === false) {
            return [$targetId];
        }

        [$start, $end] = $anchorIndex <= $targetIndex
            ? [$anchorIndex, $targetIndex]
            : [$targetIndex, $anchorIndex];

        return array_slice($ids, $start, $end - $start + 1);
    }
}

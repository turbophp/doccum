<?php

declare(strict_types=1);

namespace App\Livewire\Files;

use App\Actions\Directories\CreateDirectory;
use App\Actions\Directories\GrantDirectoryAccess;
use App\Actions\Directories\MoveDirectory;
use App\Actions\Directories\RenameDirectory;
use App\Actions\Directories\RevokeDirectoryAccess;
use App\Actions\Directories\TrashDirectory;
use App\Actions\Files\MoveFile;
use App\Actions\Files\RenameFile;
use App\Actions\Files\SetLegalHold;
use App\Actions\Files\StoreFileVersion;
use App\Actions\Files\TrashFile;
use App\Enums\AccessLevel;
use App\Enums\ArchiveStatus;
use App\Exceptions\CannotMoveDirectoryIntoItself;
use App\Exceptions\DuplicateDirectoryName;
use App\Exceptions\DuplicateFileName;
use App\Exceptions\PeriodIsArchived;
use App\Jobs\ZipDirectory;
use App\Models\Directory;
use App\Models\DirectoryArchive;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\FileVersion;
use App\Models\User;
use App\Services\DirectoryAccess;
use App\Support\EmailKey;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A permission-filtered browser: list, create a subdirectory, upload,
 * select a row and act on it, inside the three-pane shell spec §10
 * describes -- a reach-root sidebar and a real ancestor breadcrumb
 * (item/files-three-pane, issue #104/#99) alongside the centre listing
 * and detail panel.
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
#[Layout('layouts::app', ['fullBleed' => true])]
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

    /** The Access panel's "grant to" field -- an email address, looked up through EmailKey::of() the same way login and grantAccess() itself compare it. */
    public string $grantEmail = '';

    /** One of AccessLevel's string values. Plain string, not the enum, for the same reason $sort/$direction are: Livewire hydrates a typed property against whatever wire:model posts, and a raw select value is a string. See grantAccess(). */
    public string $grantLevel = 'view';

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
     * Folder rows the viewer has ticked.
     *
     * Kept apart from $selectedIds rather than mixed into it with a prefix: a
     * directory id and a file id are both integers, and one list holding both
     * is one typo away from trashing the wrong kind of thing.
     *
     * @var list<int>
     */
    public array $selectedDirectoryIds = [];

    /**
     * The archive currently being built, if any, so the view can poll it.
     */
    public ?int $archiveId = null;

    /**
     * The file being previewed, if any.
     */
    public ?int $previewFileId = null;

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

    /**
     * Opens the detail area's property panel on one subdirectory of the
     * current directory.
     *
     * At the root of the browser ($this->directory === null) the row
     * being opened is one of the LANDING PANE's reach roots, not
     * necessarily a directory with parent_id IS NULL -- a directory
     * granted directly on a nested node, with no grant on anything above
     * it, is a reach root there too (see render()'s 'directories' key and
     * DirectoryAccess::reachRootIds()). Resolving against
     * where('parent_id', null) here, as before this item, would 404 on
     * exactly the row the landing pane now shows for that case.
     */
    public function selectDirectory(int $directoryId, DirectoryAccess $access): void
    {
        // At the root the lookup accepts a true filesystem root (parent_id IS
        // NULL, which is all this branch used to accept) OR a reach root --
        // item/files-three-pane's nested case, whose parent_id is not null and
        // which the landing pane now lists, so it must be selectable from it.
        //
        // Deliberately a UNION of the two, never reachRootIds() alone. Scoping
        // the lookup to reachRootIds() would make an id the viewer cannot view
        // fail to RESOLVE, so an unviewable directory would answer 404 from
        // findOrFail() instead of 403 from the authorize() below -- swapping a
        // policy decision for an existence check. 'refuses to select a
        // directory the viewer cannot view' caught exactly that, across all
        // six test jobs: ModelNotFoundException where it asserts 403.
        //
        // Which way that SHOULD answer is issue #109's question for the whole
        // surface, and it is not this item's to settle in one method. What
        // matters here is that the answer does not change silently as a side
        // effect of a UI item: authorisation stays the gate, and the lookup
        // only ever widens what can be found.
        $rootCandidates = Directory::query()
            ->where(function (Builder $query) use ($access): void {
                $query->whereNull('parent_id')
                    ->orWhereIn('id', $access->reachRootIds(auth()->user()));
            });

        $subdirectory = $this->directory === null
            ? $rootCandidates->findOrFail($directoryId)
            : Directory::query()->where('parent_id', $this->directory->getKey())->findOrFail($directoryId);

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

        // Dispatched on success ONLY, and the dialog closes on this rather
        // than on submit: createDirectory() answers a duplicate name by adding
        // an error and returning, so a dialog that closed when the form was
        // submitted would take the explanation with it.
        $this->dispatch('folder-created');
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

        // Success only, same reasoning as folder-created above: a rejected
        // upload must leave its dialog open with the message still on screen.
        $this->dispatch('file-uploaded');
    }

    /**
     * Uploads a new version under the selected file's own name, rather than
     * creating a second file. See FilePolicy::replace() for why this is
     * gated as an upload into the file's directory plus a check on the
     * file's own trashed state, and see the $replacement property above for
     * why this never touches $upload.
     *
     * Calls StoreFileVersion::replace() -- issue #115's entry point, added
     * by item/api-presigned-upload -- rather than handle(). Before that
     * entry point existed, this method resolved $this->selectedFile's
     * directory by hand and passed handle() the selected file's OWN name
     * rather than the uploaded file's client name, because handle() decides
     * "append" or "create" purely by which string it is given; getting that
     * one argument wrong would silently create a second file instead of a
     * version (see the container smoke's checkReplaceAddsASecondVersion(),
     * PR #118). replace() takes the File directly, needs no Directory at
     * all, and cannot create one -- the guarantee is now structural rather
     * than a matter of remembering which name to pass, and this line is
     * still exactly the one a reverted image would get wrong.
     */
    public function replaceFile(StoreFileVersion $action): void
    {
        abort_if($this->selectedFile === null, 404);

        $this->authorize('replace', $this->selectedFile);

        $this->validate(['replacement' => ['required', 'file', 'max:102400']]);

        try {
            $this->selectedFile = $action->replace(
                auth()->user(),
                $this->selectedFile,
                $this->replacement->getRealPath(),
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

    /**
     * Move a dragged row into a directory.
     *
     * The same two actions and the same two policies the Move selects use --
     * dragging is a gesture, not a second set of rules. What it does NOT trust
     * is the request: the subject and the destination are both re-resolved and
     * re-authorised here, because a drop is three client-supplied integers and
     * the DOM it came from proves nothing.
     *
     * A duplicate name or a directory dropped into itself comes back as a
     * refusal the status line can show, not an exception: dragging a folder
     * onto its own child is an ordinary slip, and a stack trace is the wrong
     * answer to it.
     */
    /**
     * Trash one row from the listing, without selecting it first.
     *
     * Separate methods per kind rather than one with a type string: the two
     * take different actions and answer to different policies, and the row
     * already knows which it is. Both re-resolve and re-authorise, and the
     * directory one is scoped to children of the directory being browsed --
     * the same scoping bulkTrash() uses, for the same reason: this listing
     * renders nothing else.
     */
    public function trashFileRow(int $fileId, TrashFile $action): void
    {
        $file = File::query()->findOrFail($fileId);

        $this->authorize('delete', $file);

        $action->handle($file);

        if ($this->selectedFile?->getKey() === $file->getKey()) {
            $this->selectedFile = null;
        }

        if ($this->previewFileId === $file->getKey()) {
            $this->previewFileId = null;
        }
    }

    public function trashDirectoryRow(int $directoryId, TrashDirectory $action): void
    {
        abort_if($this->directory === null, 404);

        $subject = Directory::query()
            ->where('parent_id', $this->directory->getKey())
            ->find($directoryId);

        abort_if($subject === null, 404);

        $this->authorize('delete', $subject);

        $action->handle($subject);

        if ($this->selectedDirectory?->getKey() === $subject->getKey()) {
            $this->selectedDirectory = null;
        }
    }

    /**
     * Open a file in the preview dialog.
     *
     * Authorised with `view`, the same ability the detail panel and the
     * listing already require -- a preview shows the bytes the person could
     * download anyway, in a frame instead of a save dialog. The id is
     * re-resolved against the viewer's reach rather than trusted, because it
     * arrives from the client like any other.
     */
    public function preview(int $fileId): void
    {
        $file = File::query()->findOrFail($fileId);

        $this->authorize('view', $file);

        $this->previewFileId = $file->getKey();
    }

    public function closePreview(): void
    {
        $this->previewFileId = null;
    }

    /**
     * The file the preview dialog is showing, re-authorised on every render.
     *
     * Not cached on the component: access can be revoked between opening the
     * dialog and the next round trip, and a preview left hanging open is
     * exactly where that would go unnoticed.
     */
    public function previewFile(): ?File
    {
        if ($this->previewFileId === null) {
            return null;
        }

        $file = File::query()->find($this->previewFileId);

        if ($file === null || auth()->user()?->cannot('view', $file)) {
            return null;
        }

        return $file;
    }

    public function dropMove(string $subjectType, int $subjectId, int $targetDirectoryId, MoveFile $moveFile, MoveDirectory $moveDirectory): void
    {
        $destination = Directory::query()->findOrFail($targetDirectoryId);

        if ($subjectType === 'file') {
            $file = File::query()->findOrFail($subjectId);

            $this->authorize('move', [$file, $destination]);

            try {
                $moveFile->handle($file, $destination);
            } catch (DuplicateFileName|PeriodIsArchived $e) {
                $this->dispatch('drop-refused', reason: $e->getMessage());
            }

            return;
        }

        if ($subjectType === 'directory') {
            $subject = Directory::query()->findOrFail($subjectId);

            $this->authorize('move', [$subject, $destination]);

            try {
                $moveDirectory->handle($subject, $destination);
            } catch (DuplicateDirectoryName|CannotMoveDirectoryIntoItself $e) {
                $this->dispatch('drop-refused', reason: $e->getMessage());
            }

            return;
        }

        abort(404);
    }

    public function moveFile(MoveFile $action, DirectoryAccess $access): void
    {
        abort_if($this->selectedFile === null, 404);

        $this->validate(['moveFileDestinationId' => ['required', 'integer']]);

        // Scoped to the viewer's own reach INSIDE the query, not resolved
        // with a bare findOrFail() and left for authorize() below to
        // refuse -- settled product-wide as 404 for a destination the
        // viewer cannot even see, the same as a nonexistent id (issue
        // #109). findOrFail()-then-authorize() would leak existence
        // through a 403: the old shape answered 403 for "you cannot move
        // here" whether the destination was invisible to the viewer or
        // merely below the access LEVEL a move needs, and those are not
        // the same fact. A destination the viewer CAN see (it is inside
        // this scope) but lacks Edit on -- View-level, or nothing granted
        // beyond View -- still 403s below, from authorize('move', ...):
        // its existence is not a secret from a viewer who can already see
        // it, so that refusal stays legible rather than hidden behind a
        // 404 that would prove nothing. See moveDirectory()'s identical
        // fix and this item's report for the one test this flips.
        $reachableForFileMove = $access->viewableDirectoryIds(auth()->user());

        $destination = Directory::query()
            ->whereIn('id', $reachableForFileMove)
            ->find($this->moveFileDestinationId);

        abort_if($destination === null, 404);

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

    public function moveDirectory(MoveDirectory $action, DirectoryAccess $access): void
    {
        abort_if($this->selectedDirectory === null, 404);

        // See moveFile()'s identical comment: scoped to the viewer's own
        // reach INSIDE the query, so a destination wholly outside it 404s
        // like a nonexistent id (issue #109), rather than findOrFail()
        // finding it and authorize() below leaking its existence through a
        // 403. '' still means "the root", with nothing to scope.
        $reachableForDirectoryMove = $access->viewableDirectoryIds(auth()->user());

        $destination = $this->moveDirectoryDestinationId === ''
            ? null
            : Directory::query()
                ->whereIn('id', $reachableForDirectoryMove)
                ->find((int) $this->moveDirectoryDestinationId);

        abort_if($this->moveDirectoryDestinationId !== '' && $destination === null, 404);

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
     * Grants (or raises/lowers) a user's access on the selected directory.
     * Gated on manageAccess() alone -- per spec §5, "manage" on the
     * directory already IS the capability to grant/revoke access, and
     * DirectoryPolicy::manageAccess() requires nothing beyond that Manage
     * level (unlike move()/delete(), which additionally require
     * directories.manage -- see this method's own report for why the two
     * are deliberately different).
     */
    public function grantAccess(GrantDirectoryAccess $action): void
    {
        abort_if($this->selectedDirectory === null, 404);

        // The one ability check standing between "may view/edit this
        // directory" and "may hand ANY OTHER USER access to it" -- without
        // it, GrantDirectoryAccess itself checks nothing (CLAUDE.md: actions
        // never authorise).
        $this->authorize('manageAccess', $this->selectedDirectory);

        $this->validate([
            'grantEmail' => ['required', 'email'],
            'grantLevel' => ['required', Rule::in(array_map(
                static fn (AccessLevel $level): string => $level->value,
                AccessLevel::cases(),
            ))],
        ]);

        $grantee = User::query()->where('email', EmailKey::of($this->grantEmail))->first();

        if ($grantee === null) {
            $this->addError('grantEmail', __('No user with that email exists.'));

            return;
        }

        $action->handle($this->selectedDirectory, $grantee, AccessLevel::from($this->grantLevel));

        $this->grantEmail = '';
        $this->grantLevel = AccessLevel::View->value;
    }

    /**
     * Revokes one grant on the selected directory.
     *
     * $grantId is resolved through directoryGrantsQuery() below, never
     * looked up bare -- a grant id is an ordinary auto-increment integer a
     * caller can simply guess or enumerate, wired straight off a wire:click
     * parameter, and without that scope a manager of THIS directory could
     * revoke a grant belonging to some OTHER directory they hold no access
     * to at all, merely by naming its id. The same shape as
     * FileVersionDownloadController's cross-file 404 guard.
     */
    public function revokeAccess(int $grantId, RevokeDirectoryAccess $action): void
    {
        abort_if($this->selectedDirectory === null, 404);

        // Same ability as grantAccess() above, checked again here rather
        // than assumed from having reached this method: RevokeDirectoryAccess
        // itself checks nothing (CLAUDE.md: actions never authorise), so
        // without this call revoking is wide open to anyone who can select
        // the directory at all.
        $this->authorize('manageAccess', $this->selectedDirectory);

        // find() + abort_if, not findOrFail(): the scoping is identical either
        // way, but the REFUSAL is not. findOrFail() raises
        // ModelNotFoundException, which a real HTTP request renders as a 404
        // and a Livewire component test does not -- it propagates, so
        // assertNotFound() never sees a response and the test dies on the raw
        // exception instead. That is what it did:
        //
        //   FAILED ... refuses to revoke a grant belonging to a different
        //   directory -- ModelNotFoundException
        //
        // abort_if() is what this component already uses two lines above, and
        // everywhere else it refuses; this was the outlier.
        $grant = $this->directoryGrantsQuery($this->selectedDirectory)->find($grantId);

        abort_if($grant === null, 404);

        $action->handle($grant);
    }

    /**
     * Every user-type DirectoryGrant recorded directly against $directory --
     * never a caller-supplied, unscoped id resolved bare. Shared by
     * revokeAccess() above (so a grant id cannot cross directories) and
     * render()'s own grants listing below, which is what keeps the two from
     * ever disagreeing about which grants belong to which directory.
     *
     * User-type grants only: this v1 panel does not offer granting a ROLE
     * (DirectoryGrant supports it; GrantDirectoryAccess/this component do
     * not expose it), so there is nothing role-typed for either caller to
     * want here.
     *
     * @return Builder<DirectoryGrant>
     */
    private function directoryGrantsQuery(Directory $directory): Builder
    {
        return DirectoryGrant::query()
            ->where('directory_id', $directory->getKey())
            ->where('grantee_type', 'user');
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
    /**
     * Tick or untick a folder row.
     *
     * Plain toggling only -- no shift-range. Ranges are meaningful down a
     * single ordered list; folders and files are two sequences in one table,
     * and "everything between this folder and that file" is not a selection
     * anyone means.
     */
    public function selectDirectoryRow(int $id): void
    {
        $this->selectedDirectoryIds = in_array($id, $this->selectedDirectoryIds, true)
            ? array_values(array_diff($this->selectedDirectoryIds, [$id]))
            : [...$this->selectedDirectoryIds, $id];
    }

    /**
     * Build a zip of one folder, off the request thread.
     *
     * Authorising with `view` is the right ability rather than a new one: the
     * archive contains exactly what this viewer can already open one file at a
     * time, so a zip grants nothing extra. What it must not do is let the
     * REQUEST decide the contents -- BuildDirectoryArchive resolves those from
     * the requester's own reach, so a folder holding a subtree they cannot
     * enter still produces an archive without it.
     */
    public function downloadDirectoryZip(int $directoryId): void
    {
        $directory = Directory::query()
            ->whereIn('id', app(DirectoryAccess::class)->viewableDirectoryIds(auth()->user()))
            ->find($directoryId);

        abort_if($directory === null, 404);

        $this->authorize('view', $directory);

        $archive = DirectoryArchive::create([
            'directory_id' => $directory->getKey(),
            'requested_by' => auth()->id(),
            'status' => ArchiveStatus::Pending,
        ]);

        $this->archiveId = $archive->getKey();

        ZipDirectory::dispatch($archive);

        Flux::toast(text: __('Preparing :name.zip…', ['name' => $directory->name]));
    }

    /**
     * Polled by the view while an archive is building.
     *
     * Stops polling the moment the row reaches a terminal state, which is why
     * ArchiveStatus has one: a poller with no terminal state polls for ever.
     */
    public function archiveProgress(): ?DirectoryArchive
    {
        if ($this->archiveId === null) {
            return null;
        }

        return DirectoryArchive::query()
            ->where('requested_by', auth()->id())
            ->find($this->archiveId);
    }

    public function dismissArchive(): void
    {
        $this->archiveId = null;
    }

    public function bulkTrash(TrashFile $action, TrashDirectory $directoryAction): void
    {
        abort_if($this->directory === null, 404);

        // Scoped to the directory being browsed, exactly as selectFile()
        // scopes its own lookup and for the same reason: $selectedIds
        // arrives from the client, and the listing this component renders
        // only ever contains files from this directory, so an id from
        // anywhere else did not come from the UI.
        $files = File::query()
            ->where('directory_id', $this->directory->getKey())
            ->whereIn('id', $this->selectedIds)
            ->get();

        // Every selected id must resolve, or nothing happens at all.
        // Without this, an id that resolves to nothing -- crafted, or a file
        // someone else trashed or moved since the listing rendered -- is
        // silently dropped by whereIn(), and the method trashes a SUBSET of
        // the selection while reporting success. "Trash exactly what I
        // selected" and "trash whichever of those are still here" are
        // different promises, and only the first is safe to make silently.
        abort_if($files->count() !== count(array_unique($this->selectedIds)), 404);

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

        // Folders selected alongside the files, resolved and authorised under
        // the same all-or-nothing rule and scoped the same way: children of
        // the directory being browsed, because that is the only thing this
        // listing renders. TrashDirectory cascades to everything beneath, so
        // the bar for refusing is the same `delete` the single-folder control
        // already asks for.
        $directories = Directory::query()
            ->where('parent_id', $this->directory->getKey())
            ->whereIn('id', $this->selectedDirectoryIds)
            ->get();

        abort_if($directories->count() !== count(array_unique($this->selectedDirectoryIds)), 404);

        foreach ($directories as $selectedDirectory) {
            $this->authorize('delete', $selectedDirectory);
        }

        // Nothing is trashed until every file AND every folder has been
        // authorised -- a refusal on the last folder must not leave the first
        // file in the trash.
        foreach ($files as $file) {
            $action->handle($file);
        }

        foreach ($directories as $selectedDirectory) {
            $directoryAction->handle($selectedDirectory);
        }

        $this->selectedIds = [];
        $this->selectedDirectoryIds = [];
    }

    public function render()
    {
        $user = auth()->user();
        $access = app(DirectoryAccess::class);
        $viewable = $access->viewableDirectoryIds($user);

        // Computed once and reused for both the sidebar AND the landing
        // pane below: DirectoryAccess::reachTree() is the ONLY place reach
        // roots are resolved (CLAUDE.md's DirectoryAccess seam), so there
        // is exactly one query, and one definition of "reach root", behind
        // both surfaces.
        $sidebarTree = $access->reachTree($user);

        return view('livewire.files.browser', [
            // Filtered by the resolver, never by the view: a listing that
            // forgets this leaks the existence of directories.
            //
            // At the root of the browser ($this->directory === null) this
            // is NOT where('parent_id', null) -- that would miss a
            // directory granted directly on a nested node whose own
            // parent is not viewable, which is reachable from nowhere but
            // its own URL (issue #99). It is instead the SAME reach-root
            // set the sidebar renders, so the landing pane and the
            // sidebar can never disagree about what a viewer's reach is.
            'directories' => $this->directory === null
                ? $sidebarTree
                : Directory::query()
                    ->where('parent_id', $this->directory->getKey())
                    ->whereIn('id', $viewable)
                    ->orderBy('name')
                    ->get(),
            'sidebarTree' => $sidebarTree,
            'homeDirectory' => $this->pinnedHomeDirectory($user, $access),
            // Root first, current directory last, every entry in between
            // narrowed to $viewable -- an ancestor the viewer holds no
            // grant anywhere on (a grant made directly on a NESTED
            // directory, per DirectoryAccess) is excluded rather than
            // shown as a dead link. See breadcrumbTrail() below.
            'breadcrumbs' => $this->breadcrumbTrail($viewable),
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
            // Queried only when the viewer can manageAccess() -- not merely
            // hidden by the view's @can below -- so a non-manager's response
            // never carries another directory's grantees at all, in case a
            // future edit prints this list somewhere @can does not guard.
            // directoryGrantsQuery() is the SAME scoped query revokeAccess()
            // resolves a grant id through, so the list shown here and the
            // set of ids revokeAccess() will accept can never disagree.
            'directoryGrants' => $this->selectedDirectory !== null && $user->can('manageAccess', $this->selectedDirectory)
                ? $this->directoryGrantsQuery($this->selectedDirectory)->with('grantee')->get()
                : new Collection,
        ]);
    }

    /**
     * The viewer's own home directory, pinned above the shared tree per
     * spec §10a -- or null when auto_home is off, the row is gone, or
     * (defensively) the viewer's own grant on it is somehow gone too.
     * Resolved through DirectoryAccess::can(), the same seam as
     * everything else here, rather than assumed from the row's mere
     * existence: a "Home" link this Policy would refuse to open is worse
     * than none.
     */
    private function pinnedHomeDirectory(User $user, DirectoryAccess $access): ?Directory
    {
        $home = Directory::query()->where('home_user_id', $user->getKey())->first();

        if ($home === null || ! $access->can($user, $home, AccessLevel::View)) {
            return null;
        }

        return $home;
    }

    /**
     * The current directory's ancestor chain, root first, narrowed to
     * $viewable -- the SAME array render() resolved through
     * DirectoryAccess::viewableDirectoryIds(), never a fresh per-ancestor
     * check here. Directory::ancestorIds() includes the directory's own
     * id last, so the current directory is the final, non-linked
     * breadcrumb item.
     *
     * @param  array<int, int>  $viewable
     * @return Collection<int, Directory>
     */
    private function breadcrumbTrail(array $viewable): Collection
    {
        if ($this->directory === null) {
            return new Collection;
        }

        return Directory::query()
            ->whereIn('id', $this->directory->ancestorIds())
            ->whereIn('id', $viewable)
            ->orderBy('depth')
            ->get();
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

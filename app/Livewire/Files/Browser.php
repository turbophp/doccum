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
                $this->replacement->getClientOriginalName(),
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
            'files' => $this->directory === null
                ? collect()
                : File::query()
                    ->where('directory_id', $this->directory->getKey())
                    ->orderBy('name')
                    ->get(),
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
}

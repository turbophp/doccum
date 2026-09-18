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
use App\Actions\Files\TrashFile;
use App\Enums\AccessLevel;
use App\Exceptions\CannotMoveDirectoryIntoItself;
use App\Exceptions\DuplicateDirectoryName;
use App\Exceptions\DuplicateFileName;
use App\Exceptions\PeriodIsArchived;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;
use App\Services\DirectoryAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * The hand-built context menu (design plan §6, implementation plan Task 6):
 * row, empty space, and multi-selection, all served by one component keyed
 * on `variant` so the three share one authorisation story rather than three
 * copies of it.
 *
 * The rule this class exists to enforce -- CLAUDE.md, "Two signals, not
 * one": FilePolicy (and DirectoryPolicy) decide whether the viewer's role and
 * directory reach could EVER allow an action; that is the HIDDEN signal, and
 * it is answered the ordinary way, through $this->authorize() /
 * auth()->user()->can(). FilePolicy deliberately does not know about legal
 * hold or an archived period (see its own docblock), so a menu built on the
 * policy alone would show an enabled "Move to trash" on a held file that
 * throws the moment it is clicked. blockedReason() is the second signal: it
 * reads exactly the state TrashFile / RenameFile / MoveFile guard against,
 * so the sentence rendered here and the exception those actions throw can
 * never disagree.
 *
 * Every mutating method here authorises itself before calling an Action --
 * "Actions never authorise, callers do" (CLAUDE.md) -- which is also what
 * makes a hidden item refused server-side: hiding an item in the view never
 * substitutes for the check its handler makes on its own.
 */
class Actions extends Component
{
    /** @var 'file'|'folder'|'empty'|'multi' */
    public string $variant;

    /** Where the menu opens, in viewport pixels -- the cursor for a right-click, or the focused row's corner for Shift+F10 (design plan §6). */
    public int $originX = 0;

    public int $originY = 0;

    public ?File $file = null;

    public ?Directory $directory = null;

    /** @var list<int> */
    public array $selectedFileIds = [];

    /** The directory currently listed: where "New folder"/"Upload files…" land, and what "Select all" acts on. */
    public ?Directory $currentDirectory = null;

    public bool $renaming = false;

    public string $renameValue = '';

    public bool $moving = false;

    public ?int $moveTargetId = null;

    public bool $creatingFolder = false;

    public string $newFolderName = '';

    /**
     * @param  list<int>  $selectedFileIds
     */
    public function mount(
        string $variant,
        ?File $file = null,
        ?Directory $directory = null,
        array $selectedFileIds = [],
        ?Directory $currentDirectory = null,
    ): void {
        $this->variant = $variant;
        $this->file = $file;
        $this->directory = $directory;
        $this->selectedFileIds = $selectedFileIds;
        $this->currentDirectory = $currentDirectory;

        // Mounting must never reveal a subject the viewer cannot see, no
        // matter which items the rest of this class goes on to hide: view
        // access is the floor every other check sits on top of.
        if ($file !== null) {
            $this->authorize('view', $file);
        }

        if ($directory !== null) {
            $this->authorize('view', $directory);
        }

        if ($currentDirectory !== null) {
            $this->authorize('view', $currentDirectory);
        }

        foreach ($this->selectedFiles() as $selected) {
            $this->authorize('view', $selected);
        }
    }

    // -- The second signal: file state, read exactly as the guarded actions read it --

    /**
     * Null when nothing blocks $action on $file today; otherwise the exact
     * sentence a disabled menu item shows. Reads legal_hold and
     * ArchivePeriod the same way TrashFile / RenameFile / MoveFile do, so
     * this can never say "fine" when the action would throw.
     */
    public function blockedReason(File $file, string $action): ?string
    {
        // Only 'trash' cares about legal hold -- exactly as TrashFile does.
        // RenameFile and MoveFile never check it, so folding it in here for
        // every action would tell the operator "Under legal hold" for a
        // rename that a held file can, in fact, still do.
        if ($action === 'trash' && $file->legal_hold) {
            return __('Under legal hold');
        }

        if (in_array($action, ['trash', 'rename', 'move', 'replaceVersion'], true)) {
            $period = $this->archivePeriodFor($file);

            if ($period !== null) {
                return __('Period archived :date', ['date' => $period->archived_at?->format('j M Y')]);
            }
        }

        return null;
    }

    private function archivePeriodFor(File $file): ?ArchivePeriod
    {
        return ArchivePeriod::query()
            ->where('year', $file->period_year)
            ->where(function ($query) use ($file): void {
                $query->where('month', $file->period_month)->orWhereNull('month');
            })
            ->whereNotNull('archived_at')
            ->first();
    }

    /**
     * Whether every currently-selected file could, in principle, be trashed
     * by this viewer -- the multi-selection's own hidden signal. A multi
     * action is hidden rather than disabled when even one member fails the
     * POLICY layer: an item that some of the selection cannot ever reach is
     * not "blocked today", it is not on offer.
     */
    public function multiTrashVisible(): bool
    {
        $files = $this->selectedFiles();

        if ($files->isEmpty()) {
            return false;
        }

        return $files->every(fn (File $file): bool => auth()->user()?->can('delete', $file) ?? false);
    }

    /** The first blocking reason found across the selection, or null if none blocks it (Task 6: "blocked for any member"). */
    public function multiTrashReason(): ?string
    {
        foreach ($this->selectedFiles() as $file) {
            $reason = $this->blockedReason($file, 'trash');

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /** "3 items", for the multi-selection header/label (design plan §6). */
    public function selectionSummary(): string
    {
        $count = count($this->selectedFileIds);

        return sprintf('%d %s', $count, Str::plural('item', $count));
    }

    /** @return Collection<int, File> */
    private function selectedFiles(): Collection
    {
        if ($this->selectedFileIds === []) {
            return new Collection;
        }

        return File::query()->whereKey($this->selectedFileIds)->get();
    }

    // -- Actions: each authorises itself, then delegates. Actions never authorise; this is the caller. --

    public function trash(TrashFile $action): void
    {
        $file = $this->requireFile();

        $this->authorize('delete', $file);

        $action->handle($file);

        $this->dispatch('file-trashed', fileId: $file->getKey());
        $this->file = null;
        $this->closeTransientState();
    }

    public function trashDirectory(TrashDirectory $action): void
    {
        $directory = $this->requireDirectory();

        $this->authorize('delete', $directory);

        $action->handle($directory);

        $this->dispatch('directory-trashed', directoryId: $directory->getKey());
        $this->directory = null;
        $this->closeTransientState();
    }

    /**
     * All-or-nothing: authorises every selected file before touching any of
     * them, and runs inside a transaction so a guard tripped by the third
     * file (e.g. a hold) cannot leave the first two trashed. A menu that
     * disables the whole item for one blocked member (multiTrashReason())
     * would otherwise disagree with a partial success here.
     */
    public function trashSelection(TrashFile $action): void
    {
        $files = $this->selectedFiles();

        abort_if($files->isEmpty(), 404);

        foreach ($files as $file) {
            $this->authorize('delete', $file);
        }

        DB::transaction(function () use ($files, $action): void {
            foreach ($files as $file) {
                $action->handle($file);
            }
        });

        $this->dispatch('files-trashed', fileIds: $files->pluck('id')->all());
        $this->selectedFileIds = [];
    }

    public function placeLegalHold(SetLegalHold $action): void
    {
        $file = $this->requireFile();

        $this->authorize('legalHold', $file);

        $this->file = $action->handle($file, true);
    }

    public function liftLegalHold(SetLegalHold $action): void
    {
        $file = $this->requireFile();

        $this->authorize('legalHold', $file);

        $this->file = $action->handle($file, false);
    }

    public function placeLegalHoldForSelection(SetLegalHold $action): void
    {
        $files = $this->selectedFiles();

        abort_if($files->isEmpty(), 404);

        foreach ($files as $file) {
            $this->authorize('legalHold', $file);
        }

        foreach ($files as $file) {
            $action->handle($file, true);
        }

        $this->dispatch('files-updated', fileIds: $files->pluck('id')->all());
    }

    public function startRename(): void
    {
        $subject = $this->file ?? $this->directory;

        abort_if($subject === null, 404);

        $this->authorize('update', $subject);

        $this->renameValue = (string) $subject->name;
        $this->renaming = true;
    }

    public function submitRename(RenameFile $renameFile, RenameDirectory $renameDirectory): void
    {
        $this->validate(['renameValue' => ['required', 'string', 'max:255']]);

        $name = trim($this->renameValue);

        if ($this->file !== null) {
            $this->authorize('update', $this->file);

            try {
                $this->file = $renameFile->handle($this->file, $name);
            } catch (DuplicateFileName|PeriodIsArchived $e) {
                $this->addError('renameValue', $e->getMessage());

                return;
            }
        } elseif ($this->directory !== null) {
            $this->authorize('update', $this->directory);

            try {
                $this->directory = $renameDirectory->handle($this->directory, $name);
            } catch (DuplicateDirectoryName $e) {
                $this->addError('renameValue', $e->getMessage());

                return;
            }
        } else {
            abort(404);
        }

        $this->renaming = false;
    }

    public function startMove(): void
    {
        $subject = $this->file ?? $this->directory;

        abort_if($subject === null, 404);

        $this->authorize('update', $subject);

        $this->moveTargetId = null;
        $this->moving = true;
    }

    /**
     * Directories the viewer may move the current subject into: at least
     * edit reach, excluding the subject's own directory, and -- for a
     * directory subject -- excluding itself and its own descendants (the
     * same cycle MoveDirectory refuses).
     *
     * @return Collection<int, Directory>
     */
    public function moveTargets(): Collection
    {
        $user = auth()->user();

        if ($user === null) {
            return new Collection;
        }

        $access = app(DirectoryAccess::class);
        $ids = $access->viewableDirectoryIds($user);

        return Directory::query()
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (Directory $candidate): bool => $access->can($user, $candidate, AccessLevel::Edit))
            ->filter(function (Directory $candidate): bool {
                if ($this->file !== null) {
                    return $candidate->getKey() !== $this->file->directory_id;
                }

                if ($this->directory !== null) {
                    return ! $candidate->is($this->directory) && ! $candidate->isDescendantOf($this->directory);
                }

                return true;
            })
            ->sortBy('name')
            ->values();
    }

    public function submitMove(MoveFile $moveFile, MoveDirectory $moveDirectory): void
    {
        $this->validate(['moveTargetId' => ['required', 'integer']]);

        $target = Directory::query()->findOrFail($this->moveTargetId);

        if ($this->file !== null) {
            $this->authorize('move', [$this->file, $target]);

            try {
                $this->file = $moveFile->handle($this->file, $target);
            } catch (DuplicateFileName|PeriodIsArchived $e) {
                $this->addError('moveTargetId', $e->getMessage());

                return;
            }
        } elseif ($this->directory !== null) {
            $this->authorize('move', [$this->directory, $target]);

            try {
                $this->directory = $moveDirectory->handle($this->directory, $target);
            } catch (DuplicateDirectoryName|CannotMoveDirectoryIntoItself $e) {
                $this->addError('moveTargetId', $e->getMessage());

                return;
            }
        } else {
            abort(404);
        }

        $this->moving = false;
    }

    public function startCreateFolder(): void
    {
        abort_if($this->currentDirectory === null, 404);

        $this->authorize('create', $this->currentDirectory);

        $this->newFolderName = '';
        $this->creatingFolder = true;
    }

    public function submitCreateFolder(CreateDirectory $action): void
    {
        abort_if($this->currentDirectory === null, 404);

        $this->authorize('create', $this->currentDirectory);
        $this->validate(['newFolderName' => ['required', 'string', 'max:255']]);

        try {
            $directory = $action->handle(auth()->user(), $this->newFolderName, $this->currentDirectory);
        } catch (DuplicateDirectoryName $e) {
            $this->addError('newFolderName', $e->getMessage());

            return;
        }

        $this->dispatch('directory-created', directoryId: $directory->getKey());
        $this->creatingFolder = false;
        $this->newFolderName = '';
    }

    public function cancel(): void
    {
        $this->closeTransientState();
    }

    private function closeTransientState(): void
    {
        $this->renaming = false;
        $this->moving = false;
        $this->creatingFolder = false;
    }

    private function requireFile(): File
    {
        abort_if($this->file === null, 404);

        return $this->file;
    }

    private function requireDirectory(): Directory
    {
        abort_if($this->directory === null, 404);

        return $this->directory;
    }

    public function render(): View
    {
        return view('livewire.files.actions');
    }
}

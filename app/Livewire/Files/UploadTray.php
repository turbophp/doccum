<?php

declare(strict_types=1);

namespace App\Livewire\Files;

use App\Actions\Directories\MoveDirectory;
use App\Actions\Files\MoveFile;
use App\Actions\Files\StoreFileVersion;
use App\Exceptions\CannotMoveDirectoryIntoItself;
use App\Exceptions\DuplicateDirectoryName;
use App\Exceptions\DuplicateFileName;
use App\Exceptions\PeriodIsArchived;
use App\Models\ArchivePeriod;
use App\Models\Directory;
use App\Models\File;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The server side of drag-and-drop (design plan §6, "Drag and drop") and the
 * upload tray (design plan §8, "UploadTray"). Two things a drag can end in
 * both land here: moving an existing row that was dropped on a folder row or
 * a tree node, and storing a file dragged in from the desktop.
 *
 * resources/js/shell/dnd.js decides what the pointer looks like while a drag
 * is in the air -- the ring, the cursor, the spring-loaded folder -- and
 * mirrors these rules well enough to skip showing a ring where it already
 * knows the answer is no. None of that is authorisation. Every request that
 * reaches a method here is checked from zero, through the same
 * FilePolicy/DirectoryPolicy the row's context menu will use (Task 6), so a
 * drag handler can never move or upload anything the menu would have
 * refused. See CLAUDE.md: "Actions never authorise. Callers do." and this
 * task's own brief: "A drag handler is not an authorisation boundary."
 *
 * Uploads go through Livewire's WithFileUploads, exactly as
 * App\Livewire\Files\Browser already does: the browser reports real-bytes
 * progress for the trip from the client to temporary storage before this
 * component is ever invoked again (design plan §5, motion 11, "Progress"),
 * and updatedIncoming() below is what happens once a file actually is here.
 */
class UploadTray extends Component
{
    use WithFileUploads;

    public Directory $directory;

    /**
     * Bound to the hidden file input / drop overlay dnd.js drives via
     * `$wire.uploadMultiple('incoming', files, ...)`. An array even for a
     * single file, so one drop of several files is one round trip rather
     * than one per file.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $incoming = [];

    /**
     * Set by dnd.js immediately before an uploadMultiple() call that landed
     * on a specific folder row rather than the pane's own background --
     * design plan §6: "Folder rows under the cursor still Accept
     * individually and win over the pane." Null means "the directory this
     * tray was mounted for."
     */
    public ?int $uploadTargetDirectoryId = null;

    /**
     * One entry per upload attempt this component has actually processed,
     * oldest first -- what the tray widget lists. Never a simulated queue:
     * 'done' or 'failed' is only ever set once the server has said so, and a
     * 'failed' entry always carries the reason a person would need to read
     * (design plan §6: "failed uploads stay in the tray in attention... with
     * the reason... and a Retry").
     *
     * @var list<array{name: string, status: string, reason: string|null}>
     */
    public array $items = [];

    public function mount(Directory $directory): void
    {
        $this->directory = $directory;
    }

    /**
     * Livewire calls this once every file named in $incoming has finished
     * uploading to temporary storage. Each one is authorised and stored (or
     * recorded as failed with why) independently, so one bad file in a
     * five-file drop never sinks the other four.
     */
    public function updatedIncoming(): void
    {
        $target = $this->uploadTargetDirectoryId !== null
            ? Directory::find($this->uploadTargetDirectoryId)
            : null;
        $target ??= $this->directory;

        foreach ($this->incoming as $upload) {
            $this->storeOne($target, $upload);
        }

        $this->incoming = [];
        $this->uploadTargetDirectoryId = null;
    }

    /**
     * @param  TemporaryUploadedFile  $upload
     */
    private function storeOne(Directory $target, $upload): void
    {
        $name = (string) $upload->getClientOriginalName();

        // Gate::can rather than $this->authorize(): an upload the viewer may
        // not make is recorded in the tray with the reason, not a 500 that
        // aborts every other file in the same drop. The check itself is the
        // same FilePolicy::create the row menu's "Upload here..." item and
        // App\Livewire\Files\Browser::store() both use -- nobody gets a door
        // dnd.js merely forgot to lock.
        if (! auth()->user()?->can('create', [File::class, $target])) {
            $this->items[] = ['name' => $name, 'status' => 'failed', 'reason' => __('No upload access here')];

            return;
        }

        try {
            app(StoreFileVersion::class)->handle(
                auth()->user(),
                $target,
                (string) $upload->getRealPath(),
                $name,
                $upload->getMimeType(),
            );
        } catch (PeriodIsArchived) {
            $this->items[] = ['name' => $name, 'status' => 'failed', 'reason' => __('Period archived')];

            return;
        } catch (DuplicateFileName $e) {
            $this->items[] = ['name' => $name, 'status' => 'failed', 'reason' => $e->getMessage()];

            return;
        }

        $this->items[] = ['name' => $name, 'status' => 'done', 'reason' => null];
    }

    /**
     * dnd.js's single entry point for an internal drop -- a row (file or
     * folder) released over a folder row or a tree node (design plan §6).
     * Dispatched globally, through Livewire.dispatch(), rather than called
     * on a component id dnd.js had to go find: wherever this tray ends up
     * mounted on the page, the drop reaches it.
     */
    #[On('shell-drop')]
    public function handleDrop(string $subjectType, int $subjectId, int $targetDirectoryId): void
    {
        match ($subjectType) {
            'file' => $this->moveFile($subjectId, $targetDirectoryId),
            'directory' => $this->moveDirectory($subjectId, $targetDirectoryId),
            default => null,
        };
    }

    /**
     * Moves one file. FilePolicy::move is the same check "Move to..." in
     * the row menu will use (Task 6): edit reach into both the file's
     * current directory and the destination. MoveFile itself never
     * authorises anything (CLAUDE.md) -- that is this method's job, and it
     * runs whether the request came from a real drag or was posted
     * directly.
     */
    public function moveFile(int $fileId, int $targetDirectoryId): void
    {
        $file = File::findOrFail($fileId);
        $target = Directory::findOrFail($targetDirectoryId);

        $this->authorize('move', [$file, $target]);

        try {
            app(MoveFile::class)->handle($file, $target);
        } catch (PeriodIsArchived) {
            // The ring dnd.js shows must already have refused this before
            // the drop ever reached here (design plan §6: "any target when
            // the file's own period is archived... the menu and the drop
            // follow the same rule"). This is the backstop for a client that
            // did not know that, not the primary defence -- the drag never
            // silently does nothing either way.
            $this->dispatch('drop-refused', reason: __('Period archived'));

            return;
        } catch (DuplicateFileName $e) {
            $this->dispatch('drop-refused', reason: $e->getMessage());

            return;
        }

        $this->dispatch('drop-settled', subjectType: 'file', subjectId: $fileId, targetDirectoryId: $targetDirectoryId);
    }

    /**
     * Moves one directory. DirectoryPolicy::move requires directories.manage
     * plus manage on the directory being moved plus edit on the
     * destination -- the highest bar of any move in the shell, because
     * moving a folder moves everything beneath it.
     */
    public function moveDirectory(int $directoryId, int $targetDirectoryId): void
    {
        $directory = Directory::findOrFail($directoryId);
        $target = Directory::findOrFail($targetDirectoryId);

        $this->authorize('move', [$directory, $target]);

        try {
            app(MoveDirectory::class)->handle($directory, $target);
        } catch (CannotMoveDirectoryIntoItself|DuplicateDirectoryName $e) {
            $this->dispatch('drop-refused', reason: $e->getMessage());

            return;
        }

        $this->dispatch('drop-settled', subjectType: 'directory', subjectId: $directoryId, targetDirectoryId: $targetDirectoryId);
    }

    public function render(): View
    {
        $now = now();

        return view('livewire.files.upload-tray', [
            // A new file always lands in the current month's period (see
            // App\Actions\Files\StoreFileVersion), so whether uploading here
            // is possible at all is a single global fact, not one per
            // folder: dnd.js reads it straight off this component's own root
            // element rather than needing a per-row hook nobody else's
            // markup exposes.
            'periodArchived' => ArchivePeriod::isArchivedFor((int) $now->year, (int) $now->month),
            'canUploadHere' => (bool) auth()->user()?->can('create', [File::class, $this->directory]),
        ]);
    }
}

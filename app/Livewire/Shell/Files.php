<?php

declare(strict_types=1);

namespace App\Livewire\Shell;

use App\Models\Directory;
use App\Models\File;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The three-pane shell: tree, list, detail.
 *
 * It owns one thing — where you are and what you have selected — and passes
 * that down. The panes announce rather than navigate, so location has a single
 * owner; two components both setting it is how they drift apart.
 */
#[Layout('layouts::shell')]
class Files extends Component
{
    /** In the URL so a folder can be linked, bookmarked and reloaded. */
    #[Url(as: 'dir', except: '')]
    public string $directoryId = '';

    #[Url(as: 'file', except: '')]
    public string $fileId = '';

    public function mount(?Directory $directory = null): void
    {
        if ($directory !== null) {
            // Authorised here, at the edge, before any pane is handed it.
            $this->authorize('view', $directory);
            $this->directoryId = (string) $directory->getKey();
        }
    }

    /**
     * Both the tree and a folder row in the list emit this rather than
     * navigating themselves.
     */
    #[On('directory-selected')]
    public function openDirectory(int $directoryId): void
    {
        $directory = Directory::findOrFail($directoryId);
        $this->authorize('view', $directory);

        $this->directoryId = (string) $directoryId;
        // Selecting a folder clears a file selection: the detail pane should
        // describe the thing you just chose, not the one you left behind.
        $this->fileId = '';
    }

    #[On('file-selected')]
    public function openFile(int $fileId): void
    {
        $file = File::findOrFail($fileId);
        $this->authorize('view', $file);

        $this->fileId = (string) $fileId;
    }

    public function render(): View
    {
        $directory = $this->directoryId !== ''
            ? Directory::find((int) $this->directoryId)
            : null;

        $file = $this->fileId !== ''
            ? File::find((int) $this->fileId)
            : null;

        return view('livewire.shell.files', [
            'directory' => $directory,
            // The detail pane describes the file when one is chosen, and the
            // folder otherwise, so the pane is never empty while something is
            // selected.
            'subject' => $file ?? $directory,
        ]);
    }
}

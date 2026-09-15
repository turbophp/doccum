<?php

declare(strict_types=1);

namespace App\Livewire\Files;

use App\Actions\Directories\CreateDirectory;
use App\Actions\Files\StoreFileVersion;
use App\Exceptions\DuplicateDirectoryName;
use App\Models\Directory;
use App\Models\File;
use App\Services\DirectoryAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A deliberately minimal permission-filtered browser: list, create a
 * subdirectory, upload. The full three-pane Dropbox shell is a later plan.
 *
 * What must hold here is that it never lists or opens a directory or file the
 * viewer cannot reach -- filtering happens in the query, never in the view.
 */
#[Layout('layouts::app')]
class Browser extends Component
{
    use WithFileUploads;

    public ?Directory $directory = null;

    public string $newDirectoryName = '';

    public $upload;

    public function mount(?Directory $directory = null): void
    {
        if ($directory !== null) {
            $this->authorize('view', $directory);
        }

        $this->directory = $directory;
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

    public function render()
    {
        $viewable = app(DirectoryAccess::class)->viewableDirectoryIds(auth()->user());

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
        ]);
    }
}

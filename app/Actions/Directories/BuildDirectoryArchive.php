<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Enums\ArchiveStatus;
use App\Models\Directory;
use App\Models\DirectoryArchive;
use App\Models\File;
use App\Notifications\DirectoryArchiveReady;
use App\Services\DirectoryAccess;
use App\Services\DocumentStorage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Zips every file in a directory subtree that the archive's requester may see.
 *
 * Per CLAUDE.md this action does not authorise -- but it does SCOPE, which is
 * a different thing and is not optional: the caller has authorised "may this
 * person archive this directory", while the contents of the zip are decided
 * here, file by file, from the requester's own reach. Those two questions have
 * different answers whenever a subtree contains a directory the requester
 * cannot enter, and building the list from the subtree alone would hand them
 * its contents.
 *
 * Progress is written as it goes so a poller has something truthful to show;
 * an archive of a thousand scans is minutes of work, and a progress bar that
 * only moves at the end is a spinner with extra steps.
 */
class BuildDirectoryArchive
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly DirectoryAccess $access,
    ) {}

    public function handle(DirectoryArchive $archive): DirectoryArchive
    {
        $directory = $archive->directory;
        $requester = $archive->requester;

        if ($directory === null || $requester === null) {
            throw new RuntimeException('A directory archive needs both its directory and its requester.');
        }

        // The requester's reach, intersected with this subtree. Doing it in
        // the query rather than filtering a collection afterwards is the rule
        // this codebase holds everywhere else, and here it is also what keeps
        // an unreachable branch out of the zip.
        $reachable = $this->access->viewableDirectoryIds($requester);

        $directoryIds = Directory::query()
            ->inSubtreeOf($directory)
            ->whereIn('id', $reachable)
            ->pluck('name', 'id');

        if ($directoryIds->isEmpty()) {
            $directoryIds = collect();
        }

        $files = File::query()
            ->whereIn('directory_id', $directoryIds->keys())
            ->with(['currentVersion', 'directory'])
            ->orderBy('directory_id')
            ->orderBy('name')
            ->get();

        $archive->forceFill([
            'status' => ArchiveStatus::Building,
            'total_files' => $files->count(),
            'completed_files' => 0,
        ])->save();

        $zipPath = tempnam(sys_get_temp_dir(), 'doccum-archive-');

        if ($zipPath === false) {
            throw new RuntimeException('Could not create a temporary file for the archive.');
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Could not open {$zipPath} as a zip archive.");
        }

        // An empty directory still produces a valid archive rather than an
        // error: "nothing you may see in here" is an answer, and a zip with
        // one note in it says so better than a failure does.
        if ($files->isEmpty()) {
            $zip->addFromString(
                'README.txt',
                "This archive is empty: the directory contains no files you have access to.\n",
            );
        }

        $temporaryPaths = [];

        try {
            foreach ($files as $file) {
                $version = $file->currentVersion;

                if ($version === null) {
                    // A file row with no version has no bytes to add. It is
                    // skipped rather than fatal, but it still counts as done,
                    // or the progress would never reach its total.
                    $archive->increment('completed_files');

                    continue;
                }

                $localPath = $this->storage->downloadToTemp($version);
                $temporaryPaths[] = $localPath;

                $zip->addFile($localPath, $this->pathInsideZip($file, $directory));

                $archive->increment('completed_files');
            }

            // addFile() only records an intent; the bytes are read on close(),
            // which is why every temp file has to still exist at this point.
            if (! $zip->close()) {
                throw new RuntimeException('Could not finalise the archive.');
            }

            $objectKey = $this->storage->putArchive(
                $archive->uuid,
                $zipPath,
                $this->archiveFilename($directory),
            );

            DB::transaction(function () use ($archive, $objectKey, $zipPath): void {
                $archive->forceFill([
                    'status' => ArchiveStatus::Ready,
                    'object_key' => $objectKey,
                    'size' => filesize($zipPath) ?: 0,
                    'completed_files' => $archive->total_files,
                    'expires_at' => now()->addDay(),
                ])->save();
            });

            // Zipping outlasts the tab that asked for it often enough that the
            // progress strip cannot be the only place this is said.
            $requester->notify(new DirectoryArchiveReady($archive->refresh()));
        } catch (Throwable $e) {
            if ($zip->filename !== false) {
                @$zip->close();
            }

            throw $e;
        } finally {
            foreach ($temporaryPaths as $path) {
                @unlink($path);
            }

            @unlink($zipPath);
        }

        return $archive->refresh();
    }

    /**
     * Where one file sits inside the zip, relative to the archived directory.
     */
    private function pathInsideZip(File $file, Directory $root): string
    {
        $segments = [];

        for ($current = $file->directory; $current !== null; $current = $current->parent) {
            if ($current->getKey() === $root->getKey()) {
                break;
            }

            array_unshift($segments, $current->name);
        }

        $segments[] = $file->name;

        return implode('/', $segments);
    }

    private function archiveFilename(Directory $directory): string
    {
        return $directory->name.'.zip';
    }
}

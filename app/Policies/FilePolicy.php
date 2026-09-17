<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\File;
use App\Models\User;
use App\Services\DirectoryAccess;

class FilePolicy
{
    public function __construct(private readonly DirectoryAccess $access) {}

    public function view(User $user, File $file): bool
    {
        return $this->access->can($user, $file->directory, AccessLevel::View);
    }

    public function download(User $user, File $file): bool
    {
        return $this->view($user, $file);
    }

    public function create(User $user, Directory $directory): bool
    {
        return $user->can('files.upload')
            && $this->access->can($user, $directory, AccessLevel::Edit);
    }

    public function update(User $user, File $file): bool
    {
        return $this->access->can($user, $file->directory, AccessLevel::Edit);
    }

    public function delete(User $user, File $file): bool
    {
        return $user->can('files.delete')
            && $this->access->can($user, $file->directory, AccessLevel::Edit);
    }

    /**
     * Placing or lifting a legal hold needs both layers: the periods.manage
     * capability, because a hold is a period-purging concern, and at least
     * view reach into the file's directory, because a capability holder
     * with no reach into a directory must still learn nothing about what is
     * in it. See spec §9.
     */
    public function legalHold(User $user, File $file): bool
    {
        if (! $user->can('periods.manage')) {
            return false;
        }

        return $this->access->can($user, $file->directory, AccessLevel::View);
    }
}

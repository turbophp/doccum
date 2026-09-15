<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Exceptions\DuplicateDirectoryName;
use App\Models\Directory;
use App\Models\User;
use InvalidArgumentException;

class CreateDirectory
{
    public function handle(User $creator, string $name, ?Directory $parent = null): Directory
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A directory name cannot be empty.');
        }

        $parentId = $parent?->getKey();

        // Enforced here rather than by a unique index: an index including
        // deleted_at enforces nothing (SQL treats NULLs as distinct) and
        // partial unique indexes are not portable to MySQL.
        $taken = Directory::query()
            ->where('parent_id', $parentId)
            ->where('name', $name)
            ->exists();

        if ($taken) {
            throw DuplicateDirectoryName::in($parentId, $name);
        }

        return Directory::create([
            'parent_id' => $parentId,
            'name' => $name,
            'created_by' => $creator->getKey(),
        ])->refresh();
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ArchiveStatus;
use App\Models\Directory;
use App\Models\DirectoryArchive;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectoryArchive>
 */
class DirectoryArchiveFactory extends Factory
{
    protected $model = DirectoryArchive::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'directory_id' => Directory::factory(),
            'requested_by' => User::factory(),
            'status' => ArchiveStatus::Pending,
            'total_files' => 0,
            'completed_files' => 0,
        ];
    }
}

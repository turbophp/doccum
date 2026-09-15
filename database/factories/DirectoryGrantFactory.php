<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccessLevel;
use App\Models\Directory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\DirectoryGrant>
 */
class DirectoryGrantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'directory_id' => Directory::factory(),
            'grantee_type' => 'user',
            'grantee_id' => User::factory(),
            'level' => AccessLevel::View,
        ];
    }
}

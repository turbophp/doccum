<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Directory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\File>
 */
class FileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'directory_id' => Directory::factory(),
            'name' => fake()->unique()->words(2, true).'.pdf',
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(1_000, 5_000_000),
            'checksum' => hash('sha256', (string) fake()->unique()->uuid()),
            'created_by' => User::factory(),
        ];
    }
}

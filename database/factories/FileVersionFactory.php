<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\FileVersion>
 */
class FileVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'file_id' => File::factory(),
            'version_number' => 1,
            'object_key' => 'files/2026/01/'.fake()->uuid().'/v1/document.pdf',
            'size' => fake()->numberBetween(1_000, 5_000_000),
            'mime' => 'application/pdf',
            'checksum' => hash('sha256', (string) fake()->unique()->uuid()),
            'uploaded_by' => User::factory(),
        ];
    }
}

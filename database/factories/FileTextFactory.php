<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExtractionStatus;
use App\Models\FileText;
use App\Models\FileVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FileText>
 */
class FileTextFactory extends Factory
{
    public function definition(): array
    {
        return [
            'file_version_id' => FileVersion::factory(),
            'status' => ExtractionStatus::Done,
            'extractor' => 'plain',
            'text' => fake()->paragraph(),
            'chars' => fake()->numberBetween(50, 2000),
        ];
    }
}

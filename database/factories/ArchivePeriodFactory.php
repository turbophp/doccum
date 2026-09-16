<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ArchivePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArchivePeriod>
 */
class ArchivePeriodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'year' => fake()->unique()->numberBetween(2000, 2100),
            'month' => fake()->numberBetween(1, 12),
            'archived_at' => null,
            'purged_at' => null,
            'file_count' => 0,
            'byte_count' => 0,
            'notes' => null,
        ];
    }
}

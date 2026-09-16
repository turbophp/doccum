<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\File;
use App\Models\SearchDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchDocument>
 */
class SearchDocumentFactory extends Factory
{
    protected $model = SearchDocument::class;

    public function definition(): array
    {
        return [
            'subject_type' => 'file',
            'subject_id' => File::factory(),
            'title' => fake()->unique()->words(2, true),
            'body' => fake()->paragraph(),
            'directory_id' => null,
            'ancestor_ids' => [],
            'period_year' => null,
            'period_month' => null,
            'mime' => null,
            'extension' => null,
            'owner_id' => null,
            'indexed_at' => now(),
        ];
    }
}

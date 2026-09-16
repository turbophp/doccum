<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AppliesTo;
use App\Enums\PropertyDataType;
use App\Models\PropertyDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PropertyDefinition>
 */
class PropertyDefinitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => Str::slug(fake()->unique()->word().'_'.fake()->word().'_'.fake()->word(), '_'),
            'label' => fake()->word().' '.fake()->word(),
            'data_type' => PropertyDataType::String_,
            'options' => null,
            'is_required' => false,
            'applies_to' => AppliesTo::Both,
            'sort_order' => 0,
        ];
    }
}

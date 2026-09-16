<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Property;
use App\Models\PropertyDefinition;
use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_definition_id' => PropertyDefinition::factory(),
            'subject_type' => 'file',
            'subject_id' => File::factory(),
        ];
    }
}

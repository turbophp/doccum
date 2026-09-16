<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attribute;
use App\Models\AttributeDefinition;
use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attribute>
 */
class AttributeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'attribute_definition_id' => AttributeDefinition::factory(),
            'attributable_type' => 'file',
            'attributable_id' => File::factory(),
        ];
    }
}

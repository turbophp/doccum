<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One value for one definition on one directory or file.
 *
 * The value lives in whichever of the four typed columns its definition's
 * data type dictates, so numeric ranges and date ordering are SQL
 * comparisons rather than string comparisons. See spec §4.
 */
#[Fillable([
    'property_definition_id', 'subject_type', 'subject_id',
    'value_string', 'value_number', 'value_date', 'value_boolean',
])]
class Property extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'value_number' => 'float',
            'value_boolean' => 'boolean',
        ];
    }

    /** @return BelongsTo<PropertyDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(PropertyDefinition::class, 'property_definition_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** Resolve, or prepare, the single property for this subject and definition. */
    public static function for(Model $subject, PropertyDefinition $definition): self
    {
        return static::firstOrNew([
            'property_definition_id' => $definition->getKey(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    /** Write into whichever column this definition's type dictates. */
    public function setValue(mixed $value): self
    {
        $type = $this->definition->data_type;

        // Clear every value column first: a definition's type can be changed by
        // an admin, and a stale value in the old column would keep being read
        // by anything that looks at the column directly, such as search.
        $this->forceFill([
            'value_string' => null,
            'value_number' => null,
            'value_date' => null,
            'value_boolean' => null,
            $type->column() => $type->cast($value),
        ])->save();

        return $this;
    }

    public function getValueAttribute(): mixed
    {
        return $this->{$this->definition->data_type->column()};
    }
}

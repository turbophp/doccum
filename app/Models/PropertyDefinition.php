<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppliesTo;
use App\Enums\PropertyDataType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * What a property is: a key, a label, a data type and what it applies to.
 *
 * The value itself lives on Property, one row per subject per definition.
 * See spec §4.
 */
#[Fillable(['key', 'label', 'data_type', 'options', 'is_required', 'applies_to', 'sort_order'])]
class PropertyDefinition extends Model
{
    use HasFactory;

    /**
     * Mirror the database defaults so a newly instantiated model reports the
     * same values it will hold once persisted, without a round trip.
     */
    protected $attributes = [
        'is_required' => false,
        'applies_to' => 'both',
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'data_type' => PropertyDataType::class,
            'applies_to' => AppliesTo::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Definitions that apply to the given subject type ("directory" or "file"). */
    public function scopeFor(Builder $query, string $subject): Builder
    {
        return $query->whereIn('applies_to', [$subject, AppliesTo::Both->value]);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('label');
    }
}

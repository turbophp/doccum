<?php

declare(strict_types=1);

use App\Enums\AppliesTo;
use App\Enums\PropertyDataType;
use App\Models\PropertyDefinition;
use Illuminate\Database\QueryException;

it('stores a definition with its type', function () {
    $definition = PropertyDefinition::create([
        'key' => 'invoice_no',
        'label' => 'Invoice number',
        'data_type' => PropertyDataType::String_,
        'applies_to' => AppliesTo::File,
    ]);

    expect($definition->fresh()->data_type)->toBe(PropertyDataType::String_)
        ->and($definition->fresh()->applies_to)->toBe(AppliesTo::File)
        ->and($definition->fresh()->is_required)->toBeFalse();
});

it('refuses a duplicate key', function () {
    PropertyDefinition::factory()->create(['key' => 'invoice_no']);

    expect(fn () => PropertyDefinition::factory()->create(['key' => 'invoice_no']))
        ->toThrow(QueryException::class);
});

it('stores select options as a list', function () {
    $definition = PropertyDefinition::factory()->create([
        'data_type' => PropertyDataType::Select,
        'options' => ['draft', 'final', 'signed'],
    ]);

    expect($definition->fresh()->options)->toBe(['draft', 'final', 'signed']);
});

it('scopes definitions to what they apply to', function () {
    PropertyDefinition::factory()->create(['key' => 'a', 'applies_to' => AppliesTo::File]);
    PropertyDefinition::factory()->create(['key' => 'b', 'applies_to' => AppliesTo::Directory]);
    PropertyDefinition::factory()->create(['key' => 'c', 'applies_to' => AppliesTo::Both]);

    expect(PropertyDefinition::query()->for('file')->pluck('key')->all())->toEqualCanonicalizing(['a', 'c'])
        ->and(PropertyDefinition::query()->for('directory')->pluck('key')->all())->toEqualCanonicalizing(['b', 'c']);
});

it('orders by sort order then label', function () {
    PropertyDefinition::factory()->create(['key' => 'z', 'label' => 'Zebra', 'sort_order' => 0]);
    PropertyDefinition::factory()->create(['key' => 'a', 'label' => 'Apple', 'sort_order' => 10]);

    expect(PropertyDefinition::query()->ordered()->pluck('key')->all())->toBe(['z', 'a']);
});

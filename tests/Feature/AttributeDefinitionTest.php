<?php

declare(strict_types=1);

use App\Enums\AppliesTo;
use App\Enums\AttributeDataType;
use App\Models\AttributeDefinition;
use Illuminate\Database\QueryException;

it('stores a definition with its type', function () {
    $definition = AttributeDefinition::create([
        'key' => 'invoice_no',
        'label' => 'Invoice number',
        'data_type' => AttributeDataType::String_,
        'applies_to' => AppliesTo::File,
    ]);

    expect($definition->fresh()->data_type)->toBe(AttributeDataType::String_)
        ->and($definition->fresh()->applies_to)->toBe(AppliesTo::File)
        ->and($definition->fresh()->is_required)->toBeFalse();
});

it('refuses a duplicate key', function () {
    AttributeDefinition::factory()->create(['key' => 'invoice_no']);

    expect(fn () => AttributeDefinition::factory()->create(['key' => 'invoice_no']))
        ->toThrow(QueryException::class);
});

it('stores select options as a list', function () {
    $definition = AttributeDefinition::factory()->create([
        'data_type' => AttributeDataType::Select,
        'options' => ['draft', 'final', 'signed'],
    ]);

    expect($definition->fresh()->options)->toBe(['draft', 'final', 'signed']);
});

it('scopes definitions to what they apply to', function () {
    AttributeDefinition::factory()->create(['key' => 'a', 'applies_to' => AppliesTo::File]);
    AttributeDefinition::factory()->create(['key' => 'b', 'applies_to' => AppliesTo::Directory]);
    AttributeDefinition::factory()->create(['key' => 'c', 'applies_to' => AppliesTo::Both]);

    expect(AttributeDefinition::query()->for('file')->pluck('key')->all())->toEqualCanonicalizing(['a', 'c'])
        ->and(AttributeDefinition::query()->for('directory')->pluck('key')->all())->toEqualCanonicalizing(['b', 'c']);
});

it('orders by sort order then label', function () {
    AttributeDefinition::factory()->create(['key' => 'z', 'label' => 'Zebra', 'sort_order' => 0]);
    AttributeDefinition::factory()->create(['key' => 'a', 'label' => 'Apple', 'sort_order' => 10]);

    expect(AttributeDefinition::query()->ordered()->pluck('key')->all())->toBe(['z', 'a']);
});

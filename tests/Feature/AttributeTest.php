<?php

declare(strict_types=1);

use App\Enums\AttributeDataType;
use App\Models\Attribute;
use App\Models\AttributeDefinition;
use App\Models\Directory;
use App\Models\File;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('stores a string in the string column', function () {
    $definition = AttributeDefinition::factory()->create(['data_type' => AttributeDataType::String_]);
    $file = File::factory()->create();

    $attribute = Attribute::for($file, $definition)->setValue('ACME-001');

    expect($attribute->fresh()->value)->toBe('ACME-001')
        ->and(DB::table('attributes')->value('value_string'))->toBe('ACME-001')
        ->and(DB::table('attributes')->value('value_number'))->toBeNull();
});

it('stores a number in the number column so ranges work in sql', function () {
    $definition = AttributeDefinition::factory()->create(['data_type' => AttributeDataType::Number]);
    $file = File::factory()->create();

    Attribute::for($file, $definition)->setValue('1500.75');

    expect(DB::table('attributes')->value('value_number'))->toEqual(1500.75)
        ->and(Attribute::query()->where('value_number', '>', 1000)->count())->toBe(1)
        ->and(Attribute::query()->where('value_number', '>', 2000)->count())->toBe(0);
});

it('stores a date in the date column so ordering works', function () {
    $definition = AttributeDefinition::factory()->create(['data_type' => AttributeDataType::Date]);

    Attribute::for(File::factory()->create(), $definition)->setValue('2024-03-17');
    Attribute::for(File::factory()->create(), $definition)->setValue('2023-01-05');

    expect(Attribute::query()->orderBy('value_date')->pluck('value_date')->first())
        ->toStartWith('2023-01-05');
});

it('stores a boolean', function () {
    $definition = AttributeDefinition::factory()->create(['data_type' => AttributeDataType::Boolean]);

    $attribute = Attribute::for(File::factory()->create(), $definition)->setValue('1');

    expect($attribute->fresh()->value)->toBeTrue();
});

it('attaches to a directory as well as a file', function () {
    $definition = AttributeDefinition::factory()->create(['data_type' => AttributeDataType::String_]);
    $directory = Directory::factory()->create();

    Attribute::for($directory, $definition)->setValue('archived');

    expect($directory->fresh()->attributes()->count())->toBe(1)
        ->and(DB::table('attributes')->value('attributable_type'))->toBe('directory');
});

it('holds one value per definition per subject', function () {
    $definition = AttributeDefinition::factory()->create(['data_type' => AttributeDataType::String_]);
    $file = File::factory()->create();

    Attribute::for($file, $definition)->setValue('first');

    expect(fn () => Attribute::create([
        'attribute_definition_id' => $definition->id,
        'attributable_type' => 'file',
        'attributable_id' => $file->id,
        'value_string' => 'second',
    ]))->toThrow(QueryException::class);
});

it('overwrites rather than duplicating', function () {
    $definition = AttributeDefinition::factory()->create(['data_type' => AttributeDataType::String_]);
    $file = File::factory()->create();

    Attribute::for($file, $definition)->setValue('first');
    Attribute::for($file, $definition)->setValue('second');

    expect(Attribute::count())->toBe(1)
        ->and(Attribute::first()->value)->toBe('second');
});

it('goes away with its file', function () {
    $definition = AttributeDefinition::factory()->create(['data_type' => AttributeDataType::String_]);
    $file = File::factory()->create();
    Attribute::for($file, $definition)->setValue('x');

    $file->forceDelete();

    expect(Attribute::count())->toBe(0);
});

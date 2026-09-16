<?php

declare(strict_types=1);

use App\Enums\PropertyDataType;
use App\Models\Directory;
use App\Models\File;
use App\Models\Property;
use App\Models\PropertyDefinition;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('stores a string in the string column', function () {
    $definition = PropertyDefinition::factory()->create(['data_type' => PropertyDataType::String_]);
    $file = File::factory()->create();

    $property = Property::for($file, $definition)->setValue('ACME-001');

    expect($property->fresh()->value)->toBe('ACME-001')
        ->and(DB::table('properties')->value('value_string'))->toBe('ACME-001')
        ->and(DB::table('properties')->value('value_number'))->toBeNull();
});

it('stores a number in the number column so ranges work in sql', function () {
    $definition = PropertyDefinition::factory()->create(['data_type' => PropertyDataType::Number]);
    $file = File::factory()->create();

    Property::for($file, $definition)->setValue('1500.75');

    expect(DB::table('properties')->value('value_number'))->toEqual(1500.75)
        ->and(Property::query()->where('value_number', '>', 1000)->count())->toBe(1)
        ->and(Property::query()->where('value_number', '>', 2000)->count())->toBe(0);
});

it('stores a date in the date column so ordering works', function () {
    $definition = PropertyDefinition::factory()->create(['data_type' => PropertyDataType::Date]);

    Property::for(File::factory()->create(), $definition)->setValue('2024-03-17');
    Property::for(File::factory()->create(), $definition)->setValue('2023-01-05');

    expect(Property::query()->orderBy('value_date')->pluck('value_date')->first())
        ->toStartWith('2023-01-05');
});

it('stores a boolean', function () {
    $definition = PropertyDefinition::factory()->create(['data_type' => PropertyDataType::Boolean]);

    $property = Property::for(File::factory()->create(), $definition)->setValue('1');

    expect($property->fresh()->value)->toBeTrue();
});

it('attaches to a directory as well as a file', function () {
    $definition = PropertyDefinition::factory()->create(['data_type' => PropertyDataType::String_]);
    $directory = Directory::factory()->create();

    Property::for($directory, $definition)->setValue('archived');

    expect($directory->fresh()->properties()->count())->toBe(1)
        ->and(DB::table('properties')->value('subject_type'))->toBe('directory');
});

it('holds one value per definition per subject', function () {
    $definition = PropertyDefinition::factory()->create(['data_type' => PropertyDataType::String_]);
    $file = File::factory()->create();

    Property::for($file, $definition)->setValue('first');

    expect(fn () => Property::create([
        'property_definition_id' => $definition->id,
        'subject_type' => 'file',
        'subject_id' => $file->id,
        'value_string' => 'second',
    ]))->toThrow(QueryException::class);
});

it('overwrites rather than duplicating', function () {
    $definition = PropertyDefinition::factory()->create(['data_type' => PropertyDataType::String_]);
    $file = File::factory()->create();

    Property::for($file, $definition)->setValue('first');
    Property::for($file, $definition)->setValue('second');

    expect(Property::count())->toBe(1)
        ->and(Property::first()->value)->toBe('second');
});

it('goes away with its file', function () {
    $definition = PropertyDefinition::factory()->create(['data_type' => PropertyDataType::String_]);
    $file = File::factory()->create();
    Property::for($file, $definition)->setValue('x');

    $file->forceDelete();

    expect(Property::count())->toBe(0);
});

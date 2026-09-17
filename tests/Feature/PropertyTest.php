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

it('round-trips a value_string built from three-byte characters, through the normal save path', function () {
    // issue #39 / decision/0005. Every codepoint from U+4E00 ("\u{4E00}") is
    // in the CJK Unified Ideographs block, U+0800-U+FFFF, which UTF-8 always
    // encodes as exactly three bytes -- so 1000 of them is unambiguously
    // 3000 bytes. They must be 1000 *distinct* codepoints, not one character
    // repeated: PostgreSQL's TOAST transparently pglz-compresses a repeated
    // or short-period value before storing it, so a value like
    // str_repeat("\u{4E2D}", 1000) never reaches the byte width this test
    // means to exercise and would pass even against the pre-fix index.
    // Distinct, non-repeating codepoints defeat that compression, matching
    // real-world non-ASCII text (verified against a real PostgreSQL 16
    // instance: a single repeated character inserts fine on the buggy
    // index; this construction does not).
    //
    // 3000 bytes of value_string plus the leading bigint
    // property_definition_id is over PostgreSQL's ~2704-byte btree tuple
    // ceiling, but still well inside the 1024-*character* limit that
    // PostgreSQL and MySQL both enforce on VARCHAR(1024). Before this index
    // stopped indexing the column whole on PostgreSQL, this INSERT failed
    // there -- not at migration time, only for non-ASCII content, which is
    // exactly why the bug was invisible until a test wrote content this
    // wide and this varied.
    $definition = PropertyDefinition::factory()->create(['data_type' => PropertyDataType::String_]);
    $file = File::factory()->create();

    $value = '';
    for ($codepoint = 0x4E00; $codepoint < 0x4E00 + 1000; $codepoint++) {
        $value .= mb_chr($codepoint, 'UTF-8');
    }

    expect(mb_strlen($value))->toBe(1000)
        ->and(strlen($value))->toBe(3000);

    $property = Property::for($file, $definition)->setValue($value);

    expect($property->fresh()->value)->toBe($value)
        ->and(DB::table('properties')->value('value_string'))->toBe($value);
});

it('goes away with its file', function () {
    $definition = PropertyDefinition::factory()->create(['data_type' => PropertyDataType::String_]);
    $file = File::factory()->create();
    Property::for($file, $definition)->setValue('x');

    $file->forceDelete();

    expect(Property::count())->toBe(0);
});

<?php

declare(strict_types=1);

use App\Enums\PropertyDataType;

it('maps every type to a value column', function () {
    expect(PropertyDataType::String_->column())->toBe('value_string')
        ->and(PropertyDataType::Text->column())->toBe('value_string')
        ->and(PropertyDataType::Select->column())->toBe('value_string')
        ->and(PropertyDataType::Number->column())->toBe('value_number')
        ->and(PropertyDataType::Date->column())->toBe('value_date')
        ->and(PropertyDataType::Boolean->column())->toBe('value_boolean');
});

it('covers every case, so a new type cannot be forgotten', function () {
    foreach (PropertyDataType::cases() as $type) {
        expect($type->column())->toBeString()->not->toBeEmpty()
            ->and($type->label())->toBeString()->not->toBeEmpty();
    }
});

it('normalises a number', function () {
    expect(PropertyDataType::Number->cast('12.50'))->toBe(12.5)
        ->and(PropertyDataType::Number->cast(''))->toBeNull();
});

it('normalises a boolean', function () {
    expect(PropertyDataType::Boolean->cast('1'))->toBeTrue()
        ->and(PropertyDataType::Boolean->cast('0'))->toBeFalse()
        ->and(PropertyDataType::Boolean->cast(''))->toBeNull();
});

it('normalises a date to a storable string', function () {
    expect(PropertyDataType::Date->cast('2024-03-17'))->toBe('2024-03-17')
        ->and(PropertyDataType::Date->cast(''))->toBeNull();
});

it('trims a string and treats blank as absent', function () {
    expect(PropertyDataType::String_->cast('  hello  '))->toBe('hello')
        ->and(PropertyDataType::String_->cast('   '))->toBeNull();
});

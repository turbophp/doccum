<?php

declare(strict_types=1);

use App\Enums\AttributeDataType;

it('maps every type to a value column', function () {
    expect(AttributeDataType::String_->column())->toBe('value_string')
        ->and(AttributeDataType::Text->column())->toBe('value_string')
        ->and(AttributeDataType::Select->column())->toBe('value_string')
        ->and(AttributeDataType::Number->column())->toBe('value_number')
        ->and(AttributeDataType::Date->column())->toBe('value_date')
        ->and(AttributeDataType::Boolean->column())->toBe('value_boolean');
});

it('covers every case, so a new type cannot be forgotten', function () {
    foreach (AttributeDataType::cases() as $type) {
        expect($type->column())->toBeString()->not->toBeEmpty()
            ->and($type->label())->toBeString()->not->toBeEmpty();
    }
});

it('normalises a number', function () {
    expect(AttributeDataType::Number->cast('12.50'))->toBe(12.5)
        ->and(AttributeDataType::Number->cast(''))->toBeNull();
});

it('normalises a boolean', function () {
    expect(AttributeDataType::Boolean->cast('1'))->toBeTrue()
        ->and(AttributeDataType::Boolean->cast('0'))->toBeFalse()
        ->and(AttributeDataType::Boolean->cast(''))->toBeNull();
});

it('normalises a date to a storable string', function () {
    expect(AttributeDataType::Date->cast('2024-03-17'))->toBe('2024-03-17')
        ->and(AttributeDataType::Date->cast(''))->toBeNull();
});

it('trims a string and treats blank as absent', function () {
    expect(AttributeDataType::String_->cast('  hello  '))->toBe('hello')
        ->and(AttributeDataType::String_->cast('   '))->toBeNull();
});

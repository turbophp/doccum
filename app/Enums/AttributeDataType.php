<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\AttributeDefinition;
use Carbon\Carbon;
use Illuminate\Validation\Rule;

/**
 * The one place a data type's column, cast and validation live.
 *
 * Typed columns rather than a single stringly-typed value, so numeric ranges
 * and date ordering are SQL comparisons instead of string comparisons. See
 * spec §4.
 */
enum AttributeDataType: string
{
    case String_ = 'string';
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';
    case Select = 'select';

    public function column(): string
    {
        return match ($this) {
            self::String_, self::Text, self::Select => 'value_string',
            self::Number => 'value_number',
            self::Date => 'value_date',
            self::Boolean => 'value_boolean',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::String_ => 'Text (single line)',
            self::Text => 'Text (multi-line)',
            self::Number => 'Number',
            self::Date => 'Date',
            self::Boolean => 'Yes / no',
            self::Select => 'Choice from a list',
        };
    }

    /**
     * An empty input means the attribute is absent, not that it holds an empty
     * value -- otherwise clearing a field would still satisfy a required rule.
     */
    public function cast(mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return match ($this) {
            self::String_, self::Text, self::Select => trim((string) $value),
            self::Number => (float) $value,
            self::Date => Carbon::parse((string) $value)->toDateString(),
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOL),
        };
    }

    /** @return array<int, mixed> */
    public function rules(AttributeDefinition $definition): array
    {
        $rules = $definition->is_required ? ['required'] : ['nullable'];

        return [...$rules, ...match ($this) {
            self::String_ => ['string', 'max:1024'],
            self::Text => ['string', 'max:65535'],
            self::Number => ['numeric'],
            self::Date => ['date'],
            self::Boolean => ['boolean'],
            self::Select => ['string', Rule::in($definition->options ?? [])],
        }];
    }
}

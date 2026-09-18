<?php

declare(strict_types=1);

namespace App\Search;

use App\Models\PropertyDefinition;

/**
 * Resolves a `property_definition_id`/`property_value` filter pair into the
 * one thing both {@see Fts5SearchIndex} and {@see LikeSearchIndex} actually
 * need: which typed column on `properties` to compare, and the value cast to
 * that column's type. Shared so the two implementations cannot drift on the
 * mapping -- see the CLAUDE.md note on this seam and spec §4/§8.
 *
 * Comparing against `value_string` for every data type, or building a fresh
 * ad hoc query per implementation, is exactly the kind of divergence that
 * leaves one engine finding a property filter and another silently ignoring
 * it.
 */
final readonly class PropertyFilter
{
    private function __construct(
        public int $definitionId,
        public string $column,
        public mixed $value,
    ) {}

    /**
     * Null when the filter is absent, or a required piece is missing, or the
     * value casts to null (the same "empty means absent" rule
     * PropertyDataType::cast() applies when a property is written) -- in
     * every one of those cases the caller must search unfiltered rather than
     * filter on a garbage comparison.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function fromFilters(array $filters): ?self
    {
        if (! isset($filters['property_definition_id'], $filters['property_value'])) {
            return null;
        }

        $definition = PropertyDefinition::find($filters['property_definition_id']);

        if ($definition === null) {
            return null;
        }

        $value = $definition->data_type->cast($filters['property_value']);

        if ($value === null) {
            return null;
        }

        return new self(
            definitionId: $definition->getKey(),
            column: $definition->data_type->column(),
            value: $value,
        );
    }

    /**
     * Whether this column needs PostgreSQL's md5-hash predicate alongside the
     * real one to use `properties_property_definition_id_value_string_index`
     * -- see that migration's comment. Never relevant on SQLite/MySQL, whose
     * callers must not add it.
     */
    public function isStringColumn(): bool
    {
        return $this->column === 'value_string';
    }
}

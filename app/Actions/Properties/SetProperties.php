<?php

declare(strict_types=1);

namespace App\Actions\Properties;

use App\Exceptions\UnknownProperty;
use App\Models\Property;
use App\Models\PropertyDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Validates a set of property values against their definitions and writes
 * them for one subject, all or nothing.
 *
 * Authorisation is the CALLER's job (the subject's own policy). This action
 * assumes the decision has already been made.
 */
class SetProperties
{
    /** @param  array<string, mixed>  $values  Keyed by definition key. */
    public function handle(Model $subject, array $values): void
    {
        $subjectType = $subject->getMorphClass();

        $definitions = PropertyDefinition::query()
            ->whereIn('key', array_keys($values))
            ->get()
            ->keyBy('key');

        // Checked before validation, and independently of it: an unknown key
        // is a signal that something upstream is wrong, not a value to
        // silently drop just because every other value in the batch is fine.
        foreach (array_keys($values) as $key) {
            $definition = $definitions->get($key);

            if ($definition === null || ! $definition->applies_to->includes($subjectType)) {
                throw UnknownProperty::key($key);
            }
        }

        $rules = [];

        foreach ($definitions as $key => $definition) {
            $rules[$key] = $definition->data_type->rules($definition);
        }

        // Validate everything first, then write inside a transaction: a
        // partial write would leave the subject in a state the operator
        // never asked for and never saw.
        Validator::make($values, $rules)->validate();

        DB::transaction(function () use ($subject, $values, $definitions): void {
            foreach ($values as $key => $value) {
                $definition = $definitions[$key];
                $property = Property::for($subject, $definition);

                if ($definition->data_type->cast($value) === null) {
                    // Clearing removes the row rather than storing an empty
                    // value: "has this property" is a row's existence, not a
                    // null check. A no-op when the row never existed.
                    $property->delete();

                    continue;
                }

                $property->setValue($value);
            }
        });
    }
}

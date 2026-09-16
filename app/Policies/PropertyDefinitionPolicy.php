<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PropertyDefinition;
use App\Models\User;

/**
 * Managing property definitions is an instance-wide capability rather than
 * one scoped to any directory or file, so every ability here reduces to the
 * same Spatie permission.
 */
class PropertyDefinitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('properties.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('properties.manage');
    }

    public function update(User $user, PropertyDefinition $definition): bool
    {
        return $user->can('properties.manage');
    }

    public function delete(User $user, PropertyDefinition $definition): bool
    {
        return $user->can('properties.manage');
    }
}

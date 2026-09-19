<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\PropertyDefinitionResource;
use App\Models\PropertyDefinition;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/property-definitions -- read-only, spec §11.
 *
 * No Policy call here, on purpose: a property DEFINITION is metadata about
 * what keys exist and what they mean, not access-controlled data, and
 * nothing in the UI gates reading it either --
 * App\Livewire\Search\Results::render() lists every definition to build its
 * filter form with no authorize() call at all. properties.manage (see
 * App\Policies\PropertyDefinitionPolicy) gates changing the set, not
 * reading it; the properties:read ABILITY, checked by the route's own
 * `ability:` middleware before this controller runs, is the only gate a
 * read-only listing needs.
 */
class PropertyDefinitionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $definitions = PropertyDefinition::query()->ordered()->cursorPaginate();

        return PropertyDefinitionResource::collection($definitions);
    }
}

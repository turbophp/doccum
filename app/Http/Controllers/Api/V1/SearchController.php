<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\SearchHitResource;
use App\Models\PropertyDefinition;
use App\Services\Search;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/search?q=&type=&mime=&period=&attr[key]= -- spec §11.
 *
 * Every filter here is translated into the SAME keys
 * App\Livewire\Search\Results::filters() builds, because App\Services\
 * Search is the one place anything is allowed to search (CLAUDE.md) and it
 * resolves the caller's own reach inside the query -- there is nothing for
 * this controller to scope itself; forgetting that is exactly the mistake
 * the seam exists to make impossible.
 *
 * `attr[key]=value` resolves `key` to a PropertyDefinition, the same shape
 * the UI's own property filter uses once IT has a definition id -- this
 * endpoint is handed a human key instead, since an API caller has no
 * reason to know a definition's numeric id.
 *
 * No true cursor pagination here, unlike every other listing in this API:
 * Search::for() returns an already-limited Collection, not a paginator, and
 * making it one is out of this item's scope -- see this item's report.
 */
class SearchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['required', 'string'],
            'type' => ['nullable', 'string'],
            'mime' => ['nullable', 'string'],
            'period' => ['nullable', 'string', 'regex:/^\d{4}(-\d{2})?$/'],
            'attr' => ['nullable', 'array'],
        ]);

        $hits = app(Search::class)->for($this->user(), $validated['q'], $this->filters($validated));

        return SearchHitResource::collection($hits);
    }

    /** @param  array<string, mixed>  $validated
     * @return array<string, mixed> */
    private function filters(array $validated): array
    {
        $filters = [];

        if (($validated['type'] ?? null) !== null) {
            $filters['subject_type'] = $validated['type'];
        }

        if (($validated['mime'] ?? null) !== null) {
            $filters['mime'] = $validated['mime'];
        }

        if (($validated['period'] ?? null) !== null) {
            [$year, $month] = array_pad(explode('-', $validated['period']), 2, null);
            $filters['period_year'] = (int) $year;

            if ($month !== null) {
                $filters['period_month'] = (int) $month;
            }
        }

        $attr = $validated['attr'] ?? [];

        if (is_array($attr) && $attr !== []) {
            /** @var string $key */
            $key = array_key_first($attr);
            $definition = PropertyDefinition::query()->where('key', $key)->first();

            if ($definition !== null) {
                $filters['property_definition_id'] = $definition->getKey();
                $filters['property_value'] = $attr[$key];
            }
        }

        return $filters;
    }
}

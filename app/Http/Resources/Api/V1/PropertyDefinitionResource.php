<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\PropertyDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PropertyDefinition
 */
class PropertyDefinitionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->label,
            'data_type' => $this->data_type->value,
            'options' => $this->options,
            'is_required' => $this->is_required,
            'applies_to' => $this->applies_to->value,
            'sort_order' => $this->sort_order,
        ];
    }
}

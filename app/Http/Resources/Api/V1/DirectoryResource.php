<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Directory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Directory
 */
class DirectoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'depth' => $this->depth,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->when($this->trashed(), fn () => $this->deleted_at),
        ];
    }
}

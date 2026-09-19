<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin File
 */
class FileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'directory_id' => $this->directory_id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'mime' => $this->mime,
            'size' => $this->size,
            'checksum' => $this->checksum,
            'legal_hold' => $this->legal_hold,
            'current_version_id' => $this->current_version_id,
            'period_year' => $this->period_year,
            'period_month' => $this->period_month,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->when($this->trashed(), fn () => $this->deleted_at),
        ];
    }
}

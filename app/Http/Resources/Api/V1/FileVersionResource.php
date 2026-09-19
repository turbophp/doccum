<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\FileVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FileVersion
 */
class FileVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'file_id' => $this->file_id,
            'version_number' => $this->version_number,
            'size' => $this->size,
            'mime' => $this->mime,
            'checksum' => $this->checksum,
            'uploaded_by' => $this->uploaded_by,
            'created_at' => $this->created_at,
        ];
    }
}

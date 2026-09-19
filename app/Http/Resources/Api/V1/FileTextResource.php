<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\FileText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FileText
 */
class FileTextResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'extractor' => $this->extractor,
            'text' => $this->text,
            'chars' => $this->chars,
            'error' => $this->error,
        ];
    }
}

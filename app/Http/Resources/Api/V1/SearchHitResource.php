<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Search\SearchHit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SearchHit
 */
class SearchHitResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'title' => $this->title,
            'snippet' => $this->snippet,
            'score' => $this->score,
            'directory_id' => $this->directoryId,
        ];
    }
}

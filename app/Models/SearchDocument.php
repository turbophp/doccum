<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One flattened, searchable row projected from a Directory, File or Property.
 *
 * Kept as its own table rather than making those models directly searchable:
 * extracted text lives on `file_texts` and property values on `properties`,
 * columns that do not exist on `directories` or `files`. `ancestor_ids` is
 * what lets a search filter by what the viewer may reach without a
 * post-filter over the full result set. See spec §8.
 */
#[Fillable([
    'subject_type', 'subject_id', 'title', 'body', 'directory_id', 'ancestor_ids',
    'period_year', 'period_month', 'mime', 'extension', 'owner_id', 'indexed_at',
])]
class SearchDocument extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'directory_id' => 'integer',
            'ancestor_ids' => 'array',
            'period_year' => 'integer',
            'period_month' => 'integer',
            'owner_id' => 'integer',
            'indexed_at' => 'datetime',
        ];
    }
}

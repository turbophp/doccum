<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExtractionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The extracted text for one file version, and how it got there.
 *
 * Kept off `files` and `file_versions` deliberately: a directory listing must
 * never drag megabytes of OCR text along with it. See spec §7.
 *
 * @property ExtractionStatus $status
 */
#[Fillable(['file_version_id', 'status', 'extractor', 'text', 'chars', 'error'])]
class FileText extends Model
{
    use HasFactory;

    /**
     * Mirror the database defaults so a newly instantiated model reports the
     * same values it will hold once persisted, without a round trip.
     */
    protected $attributes = [
        'status' => 'pending',
        'chars' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ExtractionStatus::class,
            'chars' => 'integer',
        ];
    }

    /** @return BelongsTo<FileVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FileVersion::class, 'file_version_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * The stable identity of a document. Bytes live in its versions.
 *
 * period_year and period_month are fixed at creation and never move, which is
 * what lets every version of a file share one object-key prefix and makes
 * purging a period a single coherent operation. See spec §6.
 */
#[Fillable([
    'uuid', 'directory_id', 'name', 'current_version_id',
    'mime', 'size', 'checksum', 'legal_hold', 'created_by',
    // Fillable so imports and tests can pin a period explicitly. The booted()
    // hook fills them only when still null, so a supplied period always wins.
    'period_year', 'period_month',
])]
class File extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * Mirror the database defaults so a newly instantiated model reports the
     * same values it will hold once persisted, without a round trip.
     */
    protected $attributes = [
        'size' => 0,
        'legal_hold' => false,
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'period_year' => 'integer',
            'period_month' => 'integer',
            'legal_hold' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (File $file): void {
            $file->uuid ??= (string) Str::orderedUuid();

            $at = $file->created_at ?? now();
            $file->period_year ??= (int) $at->year;
            $file->period_month ??= (int) $at->month;
        });
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FileVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FileVersion::class, 'current_version_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\NameKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * The stable identity of a document. Bytes live in its versions.
 *
 * period_year and period_month are fixed at creation and never move, which is
 * what lets every version of a file share one object-key prefix and makes
 * purging a period a single coherent operation. See spec §6.
 *
 * @property string|null $name_key The comparison key behind sibling name
 *                                 uniqueness, maintained by the saving hook below. Nullable because the
 *                                 column is, so a row written around Eloquent is visibly keyless rather
 *                                 than silently colliding. See App\Support\NameKey.
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

        // Maintained here, the same as Directory::syncPath(), so that no
        // code path can create or rename a file while leaving name_key
        // stale. See App\Support\NameKey and issue #46's decision comment.
        static::saving(static function (File $file): void {
            $file->name_key = NameKey::of((string) $file->name);
        });

        // The morph columns on `properties` cannot carry a foreign key (they
        // point at either directories or files), so a file's properties are
        // not cascade-deleted by the database. Without this hook they would
        // outlive the file they were attached to.
        static::forceDeleted(static function (File $file): void {
            $file->properties()->delete();
        });
    }

    /** @return BelongsTo<Directory, $this> */
    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class);
    }

    /** @return MorphMany<Property, $this> */
    public function properties(): MorphMany
    {
        return $this->morphMany(Property::class, 'subject');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FileVersion::class);
    }

    /** @return BelongsTo<FileVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FileVersion::class, 'current_version_id');
    }

    /** The extracted text of the current version, or null if there is none yet. */
    public function extractedText(): ?string
    {
        return $this->currentVersion?->text?->text;
    }

    /**
     * Siblings are compared case-insensitively and accent-sensitively, after
     * NFC normalisation, through the persisted `name_key` column -- never
     * `where('name', ...)`, which keeps every driver's accent folding alive.
     * See App\Support\NameKey.
     *
     * @param  Builder<File>  $query
     * @return Builder<File>
     */
    public function scopeWhereNamed(Builder $query, string $name): Builder
    {
        return $query->where('name_key', NameKey::of($name));
    }
}

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

/**
 * A node in the document tree.
 *
 * `path` is a materialised path of ancestor ids ("/1/5/9/"). It is maintained
 * here rather than by callers so that no code path can create a node carrying a
 * stale path. See spec §4.
 *
 * @property string|null $name_key The comparison key behind sibling name
 *     uniqueness, maintained by the saving hook below. Nullable because the
 *     column is, so a row written around Eloquent is visibly keyless rather
 *     than silently colliding. See App\Support\NameKey.
 */
#[Fillable(['parent_id', 'name', 'home_user_id', 'created_by'])]
class Directory extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected function casts(): array
    {
        return ['depth' => 'integer'];
    }

    protected static function booted(): void
    {
        static::created(static fn (Directory $directory) => $directory->syncPath());

        // Maintained here, the same pattern as syncPath(), so that no code
        // path can create or rename a directory while leaving name_key
        // stale. See App\Support\NameKey and issue #46's decision comment.
        static::saving(static function (Directory $directory): void {
            $directory->name_key = NameKey::of((string) $directory->name);
        });

        // The morph columns on `properties` cannot carry a foreign key (they
        // point at either directories or files), so a directory's properties
        // are not cascade-deleted by the database. Without this hook they
        // would outlive the directory they were attached to.
        static::forceDeleted(static function (Directory $directory): void {
            $directory->properties()->delete();
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function homeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'home_user_id');
    }

    /** @return MorphMany<Property, $this> */
    public function properties(): MorphMany
    {
        return $this->morphMany(Property::class, 'subject');
    }

    /**
     * Recompute this node's path and depth from its parent.
     *
     * Runs after insert because the path contains this row's own id, which does
     * not exist before then. saveQuietly avoids re-firing model events.
     */
    public function syncPath(): void
    {
        $parentPath = $this->parent_id
            ? (string) self::query()->whereKey($this->parent_id)->value('path')
            : '/';

        $path = $parentPath.$this->getKey().'/';

        $this->forceFill([
            'path' => $path,
            'depth' => self::depthFor($path),
        ])->saveQuietly();
    }

    /** Every node beneath this one, excluding itself. */
    public function descendants(): Builder
    {
        return static::query()
            ->where('path', 'like', $this->path.'%')
            ->whereKeyNot($this->getKey());
    }

    /**
     * This node's id preceded by every ancestor id, root first.
     *
     * The search projection indexes this as `ancestor_ids` and the access
     * resolver expands grants against it, so the shape here is load-bearing for
     * both. See spec §5 and §8.
     *
     * @return array<int, int>
     */
    public function ancestorIds(): array
    {
        return array_values(array_map(
            'intval',
            array_filter(
                explode('/', trim($this->path, '/')),
                static fn (string $segment): bool => $segment !== '',
            ),
        ));
    }

    public function isDescendantOf(self $other): bool
    {
        return $this->isNot($other) && str_starts_with($this->path, $other->path);
    }

    /** This node and everything beneath it. */
    public function scopeInSubtreeOf(Builder $query, self $root): Builder
    {
        return $query->where('path', 'like', $root->path.'%');
    }

    public static function depthFor(string $path): int
    {
        return substr_count($path, '/') - 2;
    }

    /**
     * Siblings are compared case-insensitively and accent-sensitively, after
     * NFC normalisation, through the persisted `name_key` column -- never
     * `where('name', ...)`, which keeps every driver's accent folding alive.
     * See App\Support\NameKey.
     *
     * @param  Builder<Directory>  $query
     * @return Builder<Directory>
     */
    public function scopeWhereNamed(Builder $query, string $name): Builder
    {
        return $query->where('name_key', NameKey::of($name));
    }
}

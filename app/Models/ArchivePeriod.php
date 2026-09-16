<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ArchivePeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One year, or one year-month, and where it sits in the archive lifecycle:
 * open -> archived (read-only) -> purged. See spec §9.
 *
 * The timestamps are annotated because casts() is invisible to static
 * analysis: without these, archived_at reads as the string the column holds
 * and every date comparison against it looks like a call on a string.
 * CarbonImmutable rather than Carbon -- AppServiceProvider calls
 * Date::use(CarbonImmutable::class), so that is what a cast actually returns.
 *
 * @property int $year
 * @property int|null $month
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $purged_at
 * @property int $file_count
 * @property int $byte_count
 * @property string|null $notes
 */
#[Fillable(['year', 'month', 'archived_at', 'purged_at', 'file_count', 'byte_count', 'notes'])]
class ArchivePeriod extends Model
{
    /** @use HasFactory<ArchivePeriodFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'archived_at' => 'datetime',
            'purged_at' => 'datetime',
            'file_count' => 'integer',
            'byte_count' => 'integer',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isPurged(): bool
    {
        return $this->purged_at !== null;
    }

    /**
     * Whether the given month is covered by an archived period, either the
     * month itself or the whole year it falls within.
     */
    public static function isArchivedFor(int $year, int $month): bool
    {
        return static::query()
            ->where('year', $year)
            ->where(function ($query) use ($month): void {
                $query->where('month', $month)->orWhereNull('month');
            })
            ->whereNotNull('archived_at')
            ->exists();
    }
}

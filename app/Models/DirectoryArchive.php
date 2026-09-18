<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ArchiveStatus;
use Database\Factories\DirectoryArchiveFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One request to zip a directory, and how far it has got.
 *
 * The archive holds exactly the files ONE viewer could reach when the job
 * ran, so `requested_by` is the whole of its authorisation: see
 * DirectoryArchiveDownloadController for why re-checking the directory's
 * policy at download time would be the wrong question.
 *
 * @property ArchiveStatus $status
 * @property ?Carbon $expires_at
 * @property int $total_files
 * @property int $completed_files
 * @property ?int $size
 * @property string $uuid
 */
#[Fillable(['directory_id', 'requested_by', 'status', 'total_files', 'completed_files', 'object_key', 'size', 'error', 'expires_at'])]
class DirectoryArchive extends Model
{
    /** @use HasFactory<DirectoryArchiveFactory> */
    use HasFactory;

    /**
     * Mirror the database defaults so a newly instantiated model reports the
     * same values it will hold once persisted, without a round trip.
     */
    protected $attributes = [
        'status' => 'pending',
        'total_files' => 0,
        'completed_files' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $archive): void {
            $archive->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ArchiveStatus::class,
            'total_files' => 'integer',
            'completed_files' => 'integer',
            'size' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Directory, $this> */
    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Whole percent complete, for the progress the browser shows.
     *
     * Zero files is 100%, not a division by zero: an empty directory produces
     * a valid, empty archive and the poller must still see it finish.
     */
    public function percentComplete(): int
    {
        if ($this->total_files < 1) {
            return $this->status === ArchiveStatus::Ready ? 100 : 0;
        }

        return (int) floor(($this->completed_files / $this->total_files) * 100);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at instanceof Carbon && $this->expires_at->isPast();
    }
}

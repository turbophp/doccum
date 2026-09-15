<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One grant on table `directory_access`.
 *
 * Named DirectoryGrant because App\Services\DirectoryAccess already owns the
 * other name, and two DirectoryAccess classes would be a readability tax.
 */
#[Fillable(['directory_id', 'grantee_type', 'grantee_id', 'level'])]
class DirectoryGrant extends Model
{
    use HasFactory;

    protected $table = 'directory_access';

    protected function casts(): array
    {
        return ['level' => AccessLevel::class];
    }

    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class);
    }

    public function grantee(): MorphTo
    {
        return $this->morphTo();
    }
}

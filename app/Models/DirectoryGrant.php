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
 *
 * `level` is annotated because casts() turns it into an AccessLevel while the
 * column is a string, and phpstan reads the column. Without this, assigning
 * the enum -- which is what the cast exists for -- is reported as
 * "Property ...::$level (string) does not accept App\Enums\AccessLevel".
 * The annotation states what the cast already does rather than working around
 * it; the other five annotated models here do the same for their own casts.
 *
 * @property int $directory_id
 * @property string $grantee_type
 * @property int $grantee_id
 * @property AccessLevel $level
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

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An immutable record of one uploaded object. Rows are inserted and deleted,
 * never updated. See spec §4.
 */
#[Fillable([
    'file_id', 'version_number', 'object_key',
    'size', 'mime', 'checksum', 'uploaded_by',
])]
class FileVersion extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'file_id' => 'integer',
            'version_number' => 'integer',
            'size' => 'integer',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return HasOne<FileText, $this> */
    public function text(): HasOne
    {
        return $this->hasOne(FileText::class);
    }

    /**
     * uploaded_by is a foreign key to users, but nothing before this item
     * turned it into a relation -- a template that reached for it got the
     * raw integer. See FileVersionDownloadController, which casts file_id
     * on both sides of a strict comparison rather than trust a driver to
     * hand it back as an int; this cast is the model-level half of that
     * same belt-and-braces pair.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

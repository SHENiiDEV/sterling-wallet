<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable blank (contract, addendum, KYB form…) kept for download.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $path
 * @property string $original_name
 * @property string|null $mime_type
 * @property int $size
 * @property int|null $uploaded_by
 */
#[Fillable(['name', 'description', 'path', 'original_name', 'mime_type', 'size', 'uploaded_by'])]
class DocumentTemplate extends Model
{
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

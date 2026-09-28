<?php

namespace App\Models;

use App\Enums\DocumentActivityType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property DocumentActivityType $type
 * @property string|null $comment
 * @property array<string, mixed>|null $meta
 */
#[Fillable(['type', 'user_id', 'from_status_id', 'to_status_id', 'comment', 'meta'])]
class DocumentActivity extends Model
{
    protected function casts(): array
    {
        return [
            'type' => DocumentActivityType::class,
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<DocumentStatus, $this>
     */
    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(DocumentStatus::class, 'from_status_id');
    }

    /**
     * @return BelongsTo<DocumentStatus, $this>
     */
    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(DocumentStatus::class, 'to_status_id');
    }
}

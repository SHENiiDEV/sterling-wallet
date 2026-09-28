<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\DocumentActivityType;
use App\Enums\DocumentType;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property DocumentType $type
 * @property string|null $counterparty
 * @property int $document_status_id
 * @property int|null $owner_id
 * @property int|null $created_by
 * @property Carbon|null $due_date
 * @property string|null $notes
 * @property Carbon|null $status_changed_at
 * @property-read DocumentStatus $status
 */
#[Fillable(['title', 'type', 'counterparty', 'company_id', 'merchant_id', 'document_status_id', 'owner_id', 'created_by', 'due_date', 'notes', 'status_changed_at'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'due_date' => DateOnly::class,
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DocumentStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(DocumentStatus::class, 'document_status_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<DocumentFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(DocumentFile::class)->latest();
    }

    /**
     * @return HasMany<DocumentActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(DocumentActivity::class)->latest('id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function log(DocumentActivityType $type, ?User $user, array $attributes = []): DocumentActivity
    {
        return $this->activities()->create([
            'type' => $type,
            'user_id' => $user?->id,
            ...$attributes,
        ]);
    }

    /**
     * Past due date and not yet in a final status.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->whereDate('due_date', '<', today())
            ->whereHas('status', fn (Builder $q) => $q->where('is_final', false));
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->isPast()
            && ! $this->due_date->isToday()
            && ! $this->status->is_final;
    }
}

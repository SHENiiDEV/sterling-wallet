<?php

namespace App\Models;

use Database\Factories\DocumentStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $color
 * @property int $sort_order
 * @property bool $is_default
 * @property bool $is_final
 */
#[Fillable(['name', 'color', 'sort_order', 'is_default', 'is_final'])]
class DocumentStatus extends Model
{
    /** @use HasFactory<DocumentStatusFactory> */
    use HasFactory;

    public const COLORS = ['slate', 'blue', 'cyan', 'emerald', 'amber', 'orange', 'rose', 'violet'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_default' => 'boolean',
            'is_final' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    public static function default(): ?self
    {
        return self::query()->where('is_default', true)->first()
            ?? self::query()->ordered()->first();
    }
}

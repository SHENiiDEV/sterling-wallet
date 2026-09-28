<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 1 `base` = `rate` × `quote`.
 *
 * @property int $id
 * @property Carbon $rate_date
 * @property string $base
 * @property string $quote
 * @property string $rate
 * @property string $source
 */
#[Fillable(['rate_date', 'base', 'quote', 'rate', 'source', 'created_by'])]
class FxRate extends Model
{
    protected function casts(): array
    {
        return [
            'rate_date' => DateOnly::class,
            'rate' => 'decimal:8',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

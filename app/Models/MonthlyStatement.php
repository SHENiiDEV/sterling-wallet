<?php

namespace App\Models;

use App\Enums\StatementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $month
 * @property StatementStatus $status
 * @property string $base_currency
 * @property string $turnover
 * @property string $net_profit
 * @property string $shares_total
 * @property string $company_remainder
 * @property list<array<string, mixed>>|null $merchants
 * @property Carbon|null $closed_at
 * @property Carbon|null $calculated_at
 */
#[Fillable(['month', 'status', 'base_currency', 'turnover', 'net_profit', 'shares_total', 'company_remainder', 'merchants', 'closed_by', 'closed_at', 'calculated_at'])]
class MonthlyStatement extends Model
{
    protected function casts(): array
    {
        return [
            'status' => StatementStatus::class,
            'merchants' => 'array',
            'turnover' => 'decimal:4',
            'net_profit' => 'decimal:4',
            'shares_total' => 'decimal:4',
            'company_remainder' => 'decimal:4',
            'closed_at' => 'datetime',
            'calculated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<MonthlyStatementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(MonthlyStatementLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}

<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\ReserveEntryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property ReserveEntryType $type
 * @property string $amount
 * @property string $currency
 */
#[Fillable(['merchant_id', 'merchant_mid_id', 'daily_report_task_id', 'currency', 'type', 'amount', 'release_on', 'note', 'created_by'])]
class ReserveLedgerEntry extends Model
{
    protected function casts(): array
    {
        return [
            'type' => ReserveEntryType::class,
            'amount' => 'decimal:4',
            'release_on' => DateOnly::class,
        ];
    }

    /**
     * @return BelongsTo<MerchantMid, $this>
     */
    public function merchantMid(): BelongsTo
    {
        return $this->belongsTo(MerchantMid::class);
    }

    /**
     * @return BelongsTo<DailyReportTask, $this>
     */
    public function dailyReport(): BelongsTo
    {
        return $this->belongsTo(DailyReportTask::class, 'daily_report_task_id');
    }
}

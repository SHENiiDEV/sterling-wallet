<?php

namespace App\Models;

use App\Enums\SettlementLineType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $settlement_id
 * @property SettlementLineType $type
 * @property int|null $daily_report_task_id
 * @property int|null $reserve_ledger_entry_id
 * @property string $description
 * @property string $currency
 * @property string $amount
 * @property string $rate
 * @property string $amount_payout
 * @property-read Settlement $settlement
 * @property-read DailyReportTask|null $dailyReport
 */
#[Fillable(['settlement_id', 'type', 'daily_report_task_id', 'reserve_ledger_entry_id', 'description', 'currency', 'amount', 'rate', 'amount_payout'])]
class SettlementLine extends Model
{
    protected function casts(): array
    {
        return [
            'type' => SettlementLineType::class,
            'amount' => 'decimal:4',
            'rate' => 'decimal:8',
            'amount_payout' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Settlement, $this>
     */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    /**
     * @return BelongsTo<DailyReportTask, $this>
     */
    public function dailyReport(): BelongsTo
    {
        return $this->belongsTo(DailyReportTask::class, 'daily_report_task_id');
    }

    /**
     * @return BelongsTo<ReserveLedgerEntry, $this>
     */
    public function reserveEntry(): BelongsTo
    {
        return $this->belongsTo(ReserveLedgerEntry::class, 'reserve_ledger_entry_id');
    }
}

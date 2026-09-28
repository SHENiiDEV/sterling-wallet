<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\ReportStatus;
use App\Enums\ReserveEntryType;
use App\Enums\SettlementLineType;
use App\Enums\SettlementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int $merchant_mid_id
 * @property Carbon $report_date
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property string|null $error_log
 * @property bool $is_email_sent
 * @property array<string, mixed>|null $summary_data
 * @property-read MerchantMid $merchantMid
 * @property-read Merchant $merchant
 * @property ReportStatus $status
 * @property string $currency
 */
#[Fillable([
    'merchant_id', 'merchant_mid_id', 'report_date', 'period_from', 'period_to', 'currency', 'status',
    'generated_xlsx_path', 'generated_pdf_path', 'generated_operations_path', 'error_log',
    'sales_count', 'turnover', 'refunds_amount', 'chargebacks_amount', 'total_merchant_fee', 'total_provider_cost',
    'reserve_amount', 'net_volume', 'conversion_fee', 'net_payout', 'net_profit',
    'base_currency', 'fx_rate', 'turnover_base', 'net_profit_base', 'summary_data',
    'is_email_sent', 'email_sent_at', 'generated_at',
])]
class DailyReportTask extends Model
{
    public const MONEY_FIELDS = [
        'turnover', 'refunds_amount', 'chargebacks_amount', 'total_merchant_fee', 'total_provider_cost',
        'reserve_amount', 'net_volume', 'conversion_fee', 'net_payout', 'net_profit', 'turnover_base', 'net_profit_base',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'report_date' => DateOnly::class,
            'period_from' => DateOnly::class,
            'period_to' => DateOnly::class,
            'is_email_sent' => 'boolean',
            'email_sent_at' => 'datetime',
            'generated_at' => 'datetime',
            'sales_count' => 'integer',
            'fx_rate' => 'decimal:8',
            'summary_data' => 'array',
            ...array_fill_keys(self::MONEY_FIELDS, 'decimal:4'),
        ];
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * @return BelongsTo<MerchantMid, $this>
     */
    public function merchantMid(): BelongsTo
    {
        return $this->belongsTo(MerchantMid::class);
    }

    /**
     * @return HasMany<DailyReportSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(DailyReportSource::class);
    }

    /**
     * Provider ids this report still waits for.
     *
     * @return list<int>
     */
    public function missingProviderIds(): array
    {
        $received = $this->sources()->pluck('provider_id')->all();

        return array_values(array_diff($this->merchantMid->requiredProviderIds(), $received));
    }

    /**
     * @return HasMany<SettlementLine, $this>
     */
    public function settlementLines(): HasMany
    {
        return $this->hasMany(SettlementLine::class)->where('type', SettlementLineType::Report);
    }

    /**
     * Why the report can no longer be regenerated or deleted, if it can't:
     * money was approved or paid on it, or its reserve was released.
     */
    public function lockReason(): ?string
    {
        $settlement = Settlement::query()
            ->whereIn('status', [SettlementStatus::Approved, SettlementStatus::Settled])
            ->whereHas('lines', fn ($q) => $q->where('type', SettlementLineType::Report)->where('daily_report_task_id', $this->id))
            ->first(['number', 'status']);
        if ($settlement !== null) {
            return "It is in settlement {$settlement->number} ({$settlement->status->value}).";
        }

        if (ReserveLedgerEntry::query()->where('daily_report_task_id', $this->id)->where('type', ReserveEntryType::Release)->exists()) {
            return 'Its rolling reserve has already been released.';
        }

        return null;
    }

    /**
     * The active settlement (draft, approved or settled) that pays this report.
     */
    public function activeSettlement(): ?Settlement
    {
        return Settlement::query()
            ->whereIn('status', SettlementStatus::active())
            ->whereHas('lines', fn ($q) => $q->where('type', SettlementLineType::Report)->where('daily_report_task_id', $this->id))
            ->first();
    }
}

<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $report_date
 * @property ReportStatus $status
 * @property string $currency
 */
#[Fillable([
    'merchant_id', 'merchant_mid_id', 'report_date', 'period_from', 'period_to', 'currency', 'status',
    'is_cardaq_received', 'is_corefy_received', 'cardaq_file_path', 'corefy_file_path',
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
            'is_cardaq_received' => 'boolean',
            'is_corefy_received' => 'boolean',
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
}

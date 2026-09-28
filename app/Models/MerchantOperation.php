<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\OperationSource;
use App\Enums\OperationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property OperationSource $source
 * @property OperationType $operation_type
 * @property string $amount
 * @property string $currency
 * @property Carbon|null $report_date
 * @property Carbon|null $transaction_at
 */
#[Fillable([
    'merchant_id', 'merchant_mid_id', 'source', 'mid', 'merchant_name',
    'payment_id', 'ex_id', 'arn', 'rrn', 'approval_code', 'card_mask', 'customer_email',
    'ips', 'region', 'issuer_country', 'issuer_name',
    'trn_type', 'operation_type', 'processing_code', 'resolution',
    'report_date', 'transaction_at', 'processing_at', 'amount', 'currency',
    'eu_fee', 'non_eu_fee', 'ic_fee', 'ic_interchange', 'ic_scheme_fee', 'approve_fee', 'decline_fee', 'refund_fee',
])]
class MerchantOperation extends Model
{
    protected function casts(): array
    {
        return [
            'source' => OperationSource::class,
            'operation_type' => OperationType::class,
            'report_date' => DateOnly::class,
            'transaction_at' => 'datetime',
            'processing_at' => 'datetime',
            'amount' => 'decimal:4',
            ...array_fill_keys(['eu_fee', 'non_eu_fee', 'ic_fee', 'ic_interchange', 'ic_scheme_fee', 'approve_fee', 'decline_fee', 'refund_fee'], 'decimal:4'),
        ];
    }

    /**
     * The single place that decides what an operation is.
     *
     * @param  array{trn_type?: string|int|null, amount?: string|float|int|null, decline_fee?: string|float|null, processing_code?: string|null, resolution?: string|null}  $row
     */
    public static function classify(array $row): OperationType
    {
        $trnType = isset($row['trn_type']) ? trim((string) $row['trn_type']) : '';
        $amount = (float) ($row['amount'] ?? 0);

        if ($trnType !== '' && in_array($trnType, config('sterling.trn_types.chargeback', []), true)) {
            return OperationType::Chargeback;
        }

        if (in_array($trnType, config('sterling.trn_types.refund', []), true) || $amount < 0) {
            return OperationType::Refund;
        }

        $resolution = strtolower(trim((string) ($row['resolution'] ?? '')));
        $failed = (float) ($row['decline_fee'] ?? 0) > 0
            || strtolower((string) ($row['processing_code'] ?? '')) === 'process_failed'
            || ($resolution !== '' && ! in_array($resolution, config('sterling.successful_resolutions', []), true));

        return $failed ? OperationType::Decline : OperationType::Sale;
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

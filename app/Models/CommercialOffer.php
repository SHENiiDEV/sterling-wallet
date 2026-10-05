<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\OfferStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property OfferStatus $status
 * @property string $company_name
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $country
 * @property list<string>|null $currencies
 * @property string $fee_currency
 * @property string|null $intro
 * @property list<array{label: string, value: string}>|null $extra_fees
 * @property CarbonImmutable|null $valid_until
 * @property int|null $merchant_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property-read Merchant|null $merchant
 */
#[Fillable([
    'number', 'status', 'company_name', 'contact_name', 'contact_email', 'country', 'website', 'mcc', 'currencies',
    'expected_monthly_volume',
    'fee_visa_eu_percent', 'fee_visa_non_eu_percent', 'fee_mastercard_eu_percent', 'fee_mastercard_non_eu_percent',
    'fee_acq_eu_percent', 'fee_acq_non_eu_percent',
    'fee_success_fixed', 'fee_decline_fixed', 'fee_refund_fixed', 'fee_chargeback_fixed', 'fee_fiat_to_crypto_percent',
    'setup_fee', 'rolling_reserve_percent', 'rolling_reserve_days', 'rolling_reserve_cap', 'fee_collab_fixed', 'settlement_terms', 'fee_currency', 'extra_fees',
    'valid_until', 'intro', 'terms', 'notes', 'merchant_id', 'created_by', 'sent_at', 'decided_at',
])]
class CommercialOffer extends Model
{
    /** Fields copied one-to-one onto the merchant tariff when the offer is accepted. */
    public const TARIFF_FIELDS = [
        'fee_visa_eu_percent', 'fee_visa_non_eu_percent', 'fee_mastercard_eu_percent', 'fee_mastercard_non_eu_percent',
        'fee_acq_eu_percent', 'fee_acq_non_eu_percent',
        'fee_success_fixed', 'fee_decline_fixed', 'fee_refund_fixed', 'fee_chargeback_fixed', 'fee_collab_fixed', 'fee_fiat_to_crypto_percent',
        'rolling_reserve_percent', 'rolling_reserve_days',
    ];

    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'currencies' => 'array',
            'extra_fees' => 'array',
            'expected_monthly_volume' => 'decimal:2',
            'setup_fee' => 'decimal:2',
            'rolling_reserve_cap' => 'decimal:2',
            'rolling_reserve_days' => 'integer',
            'valid_until' => DateOnly::class,
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
            ...array_fill_keys(array_filter(self::TARIFF_FIELDS, fn ($f) => str_ends_with($f, '_percent')), 'decimal:3'),
            ...array_fill_keys(array_filter(self::TARIFF_FIELDS, fn ($f) => str_ends_with($f, '_fixed')), 'decimal:4'),
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

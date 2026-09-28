<?php

namespace App\Models;

use App\Enums\MerchantStatus;
use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $company_id
 * @property string $name
 * @property string|null $website
 * @property MerchantStatus $status
 * @property bool $is_test
 * @property int|null $crypto_provider_id
 * @property-read Company|null $company
 */
#[Fillable([
    'company_id', 'name', 'website', 'status', 'is_test', 'crypto_provider_id',
    'fee_visa_eu_percent', 'fee_visa_non_eu_percent', 'fee_mastercard_eu_percent', 'fee_mastercard_non_eu_percent',
    'fee_acq_eu_percent', 'fee_acq_non_eu_percent',
    'fee_success_fixed', 'fee_decline_fixed', 'fee_refund_fixed', 'fee_chargeback_fixed', 'fee_fiat_to_crypto_percent',
    'rolling_reserve_percent', 'rolling_reserve_days',
    'invoice_email', 'mcc', 'onboarding_status', 'notes',
])]
class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory;

    public const PERCENT_FIELDS = [
        'fee_visa_eu_percent', 'fee_visa_non_eu_percent', 'fee_mastercard_eu_percent', 'fee_mastercard_non_eu_percent',
        'fee_acq_eu_percent', 'fee_acq_non_eu_percent', 'fee_fiat_to_crypto_percent', 'rolling_reserve_percent',
    ];

    public const FIXED_FIELDS = [
        'fee_success_fixed', 'fee_decline_fixed', 'fee_refund_fixed', 'fee_chargeback_fixed',
    ];

    protected static function booted(): void
    {
        static::creating(function (Merchant $merchant) {
            $merchant->public_id ??= 'mer_'.Str::lower(Str::random(16));
        });
    }

    protected function casts(): array
    {
        return [
            'status' => MerchantStatus::class,
            'is_test' => 'boolean',
            'rolling_reserve_days' => 'integer',
            ...array_fill_keys(self::PERCENT_FIELDS, 'decimal:3'),
            ...array_fill_keys(self::FIXED_FIELDS, 'decimal:4'),
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function cryptoProvider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'crypto_provider_id');
    }

    /**
     * @return HasMany<MerchantMid, $this>
     */
    public function mids(): HasMany
    {
        return $this->hasMany(MerchantMid::class);
    }

    /**
     * @return HasMany<MerchantAcquirer, $this>
     */
    public function acquirers(): HasMany
    {
        return $this->hasMany(MerchantAcquirer::class);
    }

    /**
     * @return HasMany<MerchantCryptoWallet, $this>
     */
    public function wallets(): HasMany
    {
        return $this->hasMany(MerchantCryptoWallet::class);
    }

    /**
     * @return HasMany<MerchantOperation, $this>
     */
    public function operations(): HasMany
    {
        return $this->hasMany(MerchantOperation::class);
    }

    /**
     * @return HasMany<DailyReportTask, $this>
     */
    public function dailyReports(): HasMany
    {
        return $this->hasMany(DailyReportTask::class);
    }

    /**
     * @return HasMany<ReserveLedgerEntry, $this>
     */
    public function reserveEntries(): HasMany
    {
        return $this->hasMany(ReserveLedgerEntry::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * A live merchant needs a full tariff before reports can be calculated.
     *
     * @return list<string>
     */
    public function missingTariffFields(): array
    {
        $required = [
            'fee_visa_eu_percent', 'fee_visa_non_eu_percent',
            'fee_mastercard_eu_percent', 'fee_mastercard_non_eu_percent',
        ];

        return array_values(array_filter($required, fn (string $field) => $this->{$field} === null));
    }
}

<?php

namespace App\Models;

use App\Enums\ProviderType;
use Database\Factories\ProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property ProviderType $type
 * @property bool $is_active
 */
#[Fillable([
    'name', 'code', 'type', 'is_active', 'logo_path',
    'cost_visa_eu_percent', 'cost_visa_non_eu_percent', 'cost_mastercard_eu_percent', 'cost_mastercard_non_eu_percent',
    'cost_acq_eu_percent', 'cost_acq_non_eu_percent',
    'cost_success_fixed', 'cost_decline_fixed', 'cost_refund_fixed', 'cost_chargeback_fixed', 'cost_crypto_percent',
    'settlement_fee', 'settlement_cycle', 'min_settlement',
    'rolling_reserve_percent', 'rolling_reserve_days', 'rolling_reserve_cap', 'notes',
])]
class Provider extends Model
{
    /** @use HasFactory<ProviderFactory> */
    use HasFactory;

    public const PERCENT_FIELDS = [
        'cost_visa_eu_percent', 'cost_visa_non_eu_percent', 'cost_mastercard_eu_percent', 'cost_mastercard_non_eu_percent',
        'cost_acq_eu_percent', 'cost_acq_non_eu_percent', 'cost_crypto_percent', 'rolling_reserve_percent',
    ];

    public const FIXED_FIELDS = [
        'cost_success_fixed', 'cost_decline_fixed', 'cost_refund_fixed', 'cost_chargeback_fixed',
        'settlement_fee', 'min_settlement', 'rolling_reserve_cap',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProviderType::class,
            'is_active' => 'boolean',
            'rolling_reserve_days' => 'integer',
            ...array_fill_keys(self::PERCENT_FIELDS, 'decimal:3'),
            ...array_fill_keys(self::FIXED_FIELDS, 'decimal:4'),
        ];
    }

    /**
     * @return HasMany<MerchantMid, $this>
     */
    public function bankMids(): HasMany
    {
        return $this->hasMany(MerchantMid::class, 'bank_provider_id');
    }

    /**
     * @return HasMany<MerchantMid, $this>
     */
    public function gateMids(): HasMany
    {
        return $this->hasMany(MerchantMid::class, 'gate_provider_id');
    }

    /**
     * @return HasMany<Merchant, $this>
     */
    public function cryptoMerchants(): HasMany
    {
        return $this->hasMany(Merchant::class, 'crypto_provider_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOfType(Builder $query, ProviderType $type): void
    {
        $query->where('type', $type);
    }
}

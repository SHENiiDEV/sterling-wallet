<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\Currency;
use App\Enums\MidStatus;
use App\Enums\ProviderType;
use App\Enums\TariffProduct;
use App\Models\Concerns\RestrictedToProduct;
use Database\Factories\MerchantMidFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $merchant_id
 * @property string $mid
 * @property string|null $provider_login
 * @property Currency $currency
 * @property string|null $label
 * @property MidStatus $status
 * @property int|null $bank_provider_id
 * @property int|null $gate_provider_id
 * @property string|null $gate_mid
 * @property list<string>|null $extra_gate_mids
 * @property Carbon|null $reports_start_date
 * @property string $rolling_reserve_limit
 * @property-read Merchant $merchant
 */
#[Fillable([
    'mid', 'provider_login', 'currency', 'label', 'status', 'bank_provider_id', 'gate_provider_id', 'gate_mid', 'extra_gate_mids',
    'reports_start_date', 'rolling_reserve_limit', 'processing_limit', 'daily_norm', 'notes',
    'reported_balance', 'reported_hold', 'balance_reported_at',
])]
class MerchantMid extends Model
{
    /** @use HasFactory<MerchantMidFactory> */
    use HasFactory, RestrictedToProduct;

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'extra_gate_mids' => 'array',
            'status' => MidStatus::class,
            'reports_start_date' => DateOnly::class,
            'rolling_reserve_limit' => 'decimal:2',
            'processing_limit' => 'decimal:2',
            'daily_norm' => 'decimal:2',
            'reported_balance' => 'decimal:2',
            'reported_hold' => 'decimal:2',
            'balance_reported_at' => 'datetime',
        ];
    }

    /**
     * Every gateway account that processes under this MID, main one first.
     *
     * @return list<string>
     */
    public function gateAccounts(): array
    {
        return array_values(array_unique(array_filter([$this->gate_mid, ...($this->extra_gate_mids ?? [])])));
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function bankProvider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'bank_provider_id');
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function gateProvider(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'gate_provider_id');
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
     * Providers whose files a daily report of this MID waits for:
     * the acquirer, plus the gateway when there is one.
     *
     * @return list<int>
     */
    public function requiredProviderIds(): array
    {
        return array_values(array_filter([$this->bank_provider_id, $this->gate_provider_id]));
    }

    public function roleOf(Provider $provider): ?ProviderType
    {
        return match ($provider->id) {
            $this->bank_provider_id => ProviderType::Bank,
            $this->gate_provider_id => ProviderType::Gate,
            default => null,
        };
    }

    public function reserveBalance(): string
    {
        return (string) $this->reserveEntries()->sum('amount');
    }

    protected static function restrictToProduct(Builder $query, TariffProduct $product): void
    {
        $query->whereHas('bankProvider');
    }
}

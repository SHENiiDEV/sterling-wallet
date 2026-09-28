<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\Currency;
use App\Enums\MidStatus;
use Database\Factories\MerchantMidFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property Carbon|null $reports_start_date
 * @property string $rolling_reserve_limit
 * @property-read Merchant $merchant
 */
#[Fillable([
    'mid', 'provider_login', 'currency', 'label', 'status', 'bank_provider_id', 'gate_provider_id',
    'reports_start_date', 'rolling_reserve_limit', 'processing_limit', 'notes',
])]
class MerchantMid extends Model
{
    /** @use HasFactory<MerchantMidFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'status' => MidStatus::class,
            'reports_start_date' => DateOnly::class,
            'rolling_reserve_limit' => 'decimal:2',
            'processing_limit' => 'decimal:2',
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

    public function reserveBalance(): string
    {
        return (string) $this->reserveEntries()->sum('amount');
    }
}

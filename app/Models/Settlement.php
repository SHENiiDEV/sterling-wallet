<?php

namespace App\Models;

use App\Enums\SettlementStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $number
 * @property int $merchant_id
 * @property SettlementStatus $status
 * @property string $payout_currency
 * @property array<string, string>|null $rates
 * @property string $total_payout
 * @property int|null $wallet_id
 * @property string|null $notes
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $settled_by
 * @property Carbon|null $settled_at
 * @property string|null $tx_hash
 * @property string|null $proof_path
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property Carbon|null $created_at
 * @property-read Merchant $merchant
 */
#[Fillable([
    'number', 'merchant_id', 'status', 'payout_currency', 'rates', 'total_payout', 'wallet_id', 'notes',
    'created_by', 'approved_by', 'approved_at', 'settled_by', 'settled_at', 'tx_hash', 'proof_path',
    'cancelled_by', 'cancelled_at', 'cancel_reason',
])]
class Settlement extends Model
{
    protected function casts(): array
    {
        return [
            'status' => SettlementStatus::class,
            'rates' => 'array',
            'total_payout' => 'decimal:4',
            'approved_at' => 'datetime',
            'settled_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return HasMany<SettlementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SettlementLine::class);
    }

    /**
     * @return BelongsTo<MerchantCryptoWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(MerchantCryptoWallet::class, 'wallet_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function isEditable(): bool
    {
        return $this->status === SettlementStatus::Draft;
    }
}

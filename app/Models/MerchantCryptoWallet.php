<?php

namespace App\Models;

use App\Enums\WalletType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property WalletType $type
 * @property string $address
 * @property string|null $seed_phrase
 * @property bool $is_active
 */
#[Fillable(['type', 'label', 'currency', 'network', 'address', 'seed_phrase', 'notes', 'is_active'])]
#[Hidden(['seed_phrase'])]
class MerchantCryptoWallet extends Model
{
    protected function casts(): array
    {
        return [
            'type' => WalletType::class,
            'seed_phrase' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}

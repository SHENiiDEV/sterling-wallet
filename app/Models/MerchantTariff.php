<?php

namespace App\Models;

use App\Enums\TariffProduct;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a merchant pays for one product other than cards. The columns use
 * the tariff naming without the `fee_` prefix, so `Tariff` reads them as is.
 *
 * @property int $id
 * @property int $merchant_id
 * @property TariffProduct $product
 * @property string|null $transfer_percent
 * @property string $success_fixed
 * @property string $refund_fixed
 * @property string $fiat_to_crypto_percent
 * @property string $settlement_fx_percent
 */
#[Fillable(['merchant_id', 'product', 'transfer_percent', 'success_fixed', 'refund_fixed', 'fiat_to_crypto_percent', 'settlement_fx_percent'])]
class MerchantTariff extends Model
{
    public const FIELDS = ['transfer_percent', 'success_fixed', 'refund_fixed', 'fiat_to_crypto_percent', 'settlement_fx_percent'];

    protected function casts(): array
    {
        return [
            'product' => TariffProduct::class,
            'transfer_percent' => 'decimal:3',
            'success_fixed' => 'decimal:4',
            'refund_fixed' => 'decimal:4',
            'fiat_to_crypto_percent' => 'decimal:3',
            'settlement_fx_percent' => 'decimal:3',
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

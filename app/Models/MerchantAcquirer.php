<?php

namespace App\Models;

use App\Enums\AcquirerStatus;
use App\Enums\IntegrationStatus;
use App\Enums\TariffProduct;
use App\Models\Concerns\RestrictedToProduct;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $merchant_id
 * @property int $provider_id
 * @property AcquirerStatus $status
 * @property string|null $limit
 * @property string $limit_currency
 * @property IntegrationStatus $integration_status
 * @property string|null $psp
 * @property string|null $mcc
 * @property string|null $notes
 * @property-read Provider $provider
 * @property-read Merchant $merchant
 */
#[Fillable(['merchant_id', 'provider_id', 'status', 'limit', 'limit_currency', 'integration_status', 'psp', 'mcc', 'notes'])]
class MerchantAcquirer extends Model
{
    use RestrictedToProduct;

    protected function casts(): array
    {
        return [
            'status' => AcquirerStatus::class,
            'integration_status' => IntegrationStatus::class,
            'limit' => 'decimal:2',
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
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    protected static function restrictToProduct(Builder $query, TariffProduct $product): void
    {
        $query->whereHas('provider');
    }
}

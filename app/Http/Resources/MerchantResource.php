<?php

namespace App\Http\Resources;

use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * @mixin Merchant
 */
class MerchantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_test' => $this->is_test,
            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn () => $this->company ? ['id' => $this->company->id, 'name' => $this->company->name] : null),
            'crypto_provider_id' => $this->crypto_provider_id,
            'crypto_provider' => $this->whenLoaded('cryptoProvider', fn () => $this->cryptoProvider?->name),
            ...Arr::only($this->resource->toArray(), [...Merchant::PERCENT_FIELDS, ...Merchant::FIXED_FIELDS]),
            'rolling_reserve_days' => $this->rolling_reserve_days,
            'invoice_email' => $this->invoice_email,
            'mcc' => $this->mcc,
            'onboarding_status' => $this->onboarding_status,
            'notes' => $this->notes,
            'mids' => $this->whenLoaded('mids', fn () => MerchantMidResource::collection($this->mids)->resolve()),
            'missing_tariff' => $this->missingTariffFields(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

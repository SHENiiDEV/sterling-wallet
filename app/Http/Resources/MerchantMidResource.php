<?php

namespace App\Http\Resources;

use App\Models\MerchantMid;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MerchantMid
 */
class MerchantMidResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mid' => $this->mid,
            'provider_login' => $this->provider_login,
            'currency' => $this->currency->value,
            'label' => $this->label,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'bank_provider_id' => $this->bank_provider_id,
            'gate_provider_id' => $this->gate_provider_id,
            'gate_mid' => $this->gate_mid,
            'bank_provider' => $this->whenLoaded('bankProvider', fn () => $this->bankProvider?->name),
            'gate_provider' => $this->whenLoaded('gateProvider', fn () => $this->gateProvider?->name),
            'reports_start_date' => $this->reports_start_date?->toDateString(),
            'rolling_reserve_limit' => $this->rolling_reserve_limit,
            'processing_limit' => $this->processing_limit,
            'reserve_balance' => $this->when(isset($this->reserve_balance), fn () => (string) ($this->reserve_balance ?? '0')),
            'notes' => $this->notes,
        ];
    }
}

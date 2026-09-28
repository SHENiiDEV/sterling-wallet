<?php

namespace App\Http\Resources;

use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * @mixin Provider
 */
class ProviderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'is_active' => $this->is_active,
            'settlement_cycle' => $this->settlement_cycle,
            'rolling_reserve_days' => $this->rolling_reserve_days,
            'notes' => $this->notes,
            ...Arr::only($this->resource->toArray(), [...Provider::PERCENT_FIELDS, ...Provider::FIXED_FIELDS]),
            'mids_count' => $this->when(
                isset($this->bank_mids_count) || isset($this->gate_mids_count),
                fn () => (int) ($this->bank_mids_count ?? 0) + (int) ($this->gate_mids_count ?? 0),
            ),
            'merchants_count' => $this->whenCounted('cryptoMerchants'),
        ];
    }
}

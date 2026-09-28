<?php

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? ['id' => $this->parent->id, 'name' => $this->parent->name] : null),
            'registration_number' => $this->registration_number,
            'country' => $this->country,
            'billing_details' => $this->billing_details,
            'notes' => $this->notes,
            'merchants_count' => $this->whenCounted('merchants'),
            'children_count' => $this->whenCounted('children'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

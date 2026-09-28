<?php

namespace App\Http\Resources;

use App\Models\DocumentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentStatus
 */
class DocumentStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'sort_order' => $this->sort_order,
            'is_default' => $this->is_default,
            'is_final' => $this->is_final,
            'documents_count' => $this->whenCounted('documents'),
        ];
    }
}

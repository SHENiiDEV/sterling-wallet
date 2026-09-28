<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'counterparty' => $this->counterparty,
            'notes' => $this->notes,
            'due_date' => $this->due_date?->toDateString(),
            'is_overdue' => $this->isOverdue(),
            'status' => DocumentStatusResource::make($this->status)->resolve(),
            'company' => $this->company ? ['id' => $this->company->id, 'name' => $this->company->name] : null,
            'merchant' => $this->merchant ? ['id' => $this->merchant->id, 'name' => $this->merchant->name, 'public_id' => $this->merchant->public_id] : null,
            'owner' => $this->owner ? ['id' => $this->owner->id, 'name' => $this->owner->name] : null,
            'files_count' => $this->whenCounted('files'),
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

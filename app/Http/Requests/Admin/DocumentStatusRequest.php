<?php

namespace App\Http\Requests\Admin;

use App\Models\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64', Rule::unique('document_statuses', 'name')->ignore($this->route('document_status'))],
            'color' => ['required', Rule::in(DocumentStatus::COLORS)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_default' => ['boolean'],
            'is_final' => ['boolean'],
        ];
    }
}

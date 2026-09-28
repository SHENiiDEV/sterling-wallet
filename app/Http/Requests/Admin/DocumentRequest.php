<?php

namespace App\Http\Requests\Admin;

use App\Enums\DocumentType;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentRequest extends FormRequest
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
        $creating = $this->isMethod('post');

        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(DocumentType::class)],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'owner_id' => ['nullable', Rule::exists('users', 'id')->whereNot('role', UserRole::Merchant->value)],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'document_status_id' => [$creating ? 'nullable' : 'prohibited', 'exists:document_statuses,id'],
            'files' => [$creating ? 'nullable' : 'prohibited', 'array', 'max:10'],
            'files.*' => ['file', 'max:'.DocumentFileRules::MAX_KB, 'mimes:'.DocumentFileRules::MIMES],
        ];
    }
}

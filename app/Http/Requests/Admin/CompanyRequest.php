<?php

namespace App\Http\Requests\Admin;

use App\Models\Company;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class CompanyRequest extends FormRequest
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
        /** @var Company|null $company */
        $company = $this->route('company');

        return [
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => [
                'nullable', 'exists:companies,id',
                function (string $attribute, mixed $value, Closure $fail) use ($company) {
                    if ($company && $value && in_array((int) $value, $company->descendantIdsWithSelf(), true)) {
                        $fail('A company cannot be nested under itself or its subsidiaries.');
                    }
                },
            ],
            'registration_number' => ['nullable', 'string', 'max:64'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'billing_details' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('country')) {
            $this->merge(['country' => strtoupper((string) $this->input('country'))]);
        }
    }
}

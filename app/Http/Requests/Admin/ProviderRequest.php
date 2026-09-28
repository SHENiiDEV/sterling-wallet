<?php

namespace App\Http\Requests\Admin;

use App\Enums\ProviderType;
use App\Models\Provider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProviderRequest extends FormRequest
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
        $nullablePercent = ['nullable', 'numeric', 'min:0', 'max:100'];
        $percent = ['required', 'numeric', 'min:0', 'max:100'];
        $fixed = ['required', 'numeric', 'min:0', 'max:1000000'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', 'alpha_dash', Rule::unique('providers', 'code')->ignore($this->route('provider'))],
            'type' => ['required', Rule::enum(ProviderType::class)],
            'is_active' => ['boolean'],
            'cost_visa_eu_percent' => $nullablePercent,
            'cost_visa_non_eu_percent' => $nullablePercent,
            'cost_mastercard_eu_percent' => $nullablePercent,
            'cost_mastercard_non_eu_percent' => $nullablePercent,
            ...array_fill_keys(array_diff(Provider::PERCENT_FIELDS, [
                'cost_visa_eu_percent', 'cost_visa_non_eu_percent', 'cost_mastercard_eu_percent', 'cost_mastercard_non_eu_percent',
            ]), $percent),
            ...array_fill_keys(Provider::FIXED_FIELDS, $fixed),
            'settlement_cycle' => ['nullable', 'string', 'max:32'],
            'rolling_reserve_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Enums\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommercialOfferRequest extends FormRequest
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
        $percent = ['nullable', 'numeric', 'min:0', 'max:100'];
        $fixed = ['required', 'numeric', 'min:0', 'max:1000000'];

        return [
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'website' => ['nullable', 'string', 'max:255'],
            'mcc' => ['nullable', 'string', 'max:8'],
            'currencies' => ['array'],
            'currencies.*' => [Rule::enum(Currency::class)],
            'expected_monthly_volume' => ['nullable', 'numeric', 'min:0', 'max:10000000000'],
            'fee_visa_eu_percent' => $percent,
            'fee_visa_non_eu_percent' => $percent,
            'fee_mastercard_eu_percent' => $percent,
            'fee_mastercard_non_eu_percent' => $percent,
            'fee_success_fixed' => $fixed,
            'fee_decline_fixed' => $fixed,
            'fee_refund_fixed' => $fixed,
            'fee_chargeback_fixed' => $fixed,
            'fee_collab_fixed' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'rolling_reserve_cap' => ['nullable', 'numeric', 'min:0', 'max:10000000000'],
            'fee_fiat_to_crypto_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'setup_fee' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'rolling_reserve_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'rolling_reserve_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'settlement_terms' => ['nullable', 'string', 'max:255'],
            'fee_currency' => ['required', Rule::enum(Currency::class)],
            'extra_fees' => ['array', 'max:20'],
            'extra_fees.*.label' => ['required', 'string', 'max:150'],
            'extra_fees.*.value' => ['required', 'string', 'max:60'],
            'intro' => ['nullable', 'string', 'max:3000'],
            'valid_until' => ['nullable', 'date'],
            'terms' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * The fallback (unknown card brand) rate is not asked for: it is the
     * dearest offered scheme rate per region. A scheme marked N/A is not
     * offered and stays empty.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);
        if ($key !== null) {
            return $data;
        }

        foreach (['eu', 'non_eu'] as $region) {
            $rates = array_filter([$data["fee_visa_{$region}_percent"] ?? null, $data["fee_mastercard_{$region}_percent"] ?? null], fn ($v) => $v !== null && $v !== '');
            $data["fee_acq_{$region}_percent"] = $rates === [] ? 0 : max(array_map('floatval', $rates));
        }

        return $data;
    }

    protected function prepareForValidation(): void
    {
        // "N/A" (or an empty field) means the scheme is not offered.
        foreach (['fee_visa_eu_percent', 'fee_visa_non_eu_percent', 'fee_mastercard_eu_percent', 'fee_mastercard_non_eu_percent'] as $field) {
            $value = $this->input($field);
            if (is_string($value) && in_array(strtoupper(trim($value)), ['', 'N/A', 'NA', '-'], true)) {
                $this->merge([$field => null]);
            }
        }
        if (! $this->filled('fee_currency')) {
            $this->merge(['fee_currency' => 'EUR']);
        }
        if ($this->filled('country')) {
            $this->merge(['country' => strtoupper((string) $this->input('country'))]);
        }
    }
}

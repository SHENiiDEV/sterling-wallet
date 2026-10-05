<?php

namespace App\Http\Requests\Admin;

use App\Enums\MerchantStatus;
use App\Enums\ProviderType;
use App\Models\Merchant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MerchantRequest extends FormRequest
{
    private const SCHEME_FIELDS = ['fee_visa_eu_percent', 'fee_visa_non_eu_percent', 'fee_mastercard_eu_percent', 'fee_mastercard_non_eu_percent'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cardPercent = ['nullable', 'numeric', 'min:0', 'max:100'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'status' => ['required', Rule::enum(MerchantStatus::class)],
            'is_test' => ['boolean'],
            'crypto_provider_id' => ['nullable', Rule::exists('providers', 'id')->where('type', ProviderType::Crypto->value)],
            'fee_visa_eu_percent' => $cardPercent,
            'fee_visa_non_eu_percent' => $cardPercent,
            'fee_mastercard_eu_percent' => $cardPercent,
            'fee_mastercard_non_eu_percent' => $cardPercent,
            'fee_acq_eu_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'fee_acq_non_eu_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'fee_fiat_to_crypto_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'rolling_reserve_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'fee_collab_fixed' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            ...array_fill_keys(Merchant::FIXED_FIELDS, ['required', 'numeric', 'min:0', 'max:1000000']),
            'rolling_reserve_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'invoice_email' => ['nullable', 'email', 'max:255'],
            'mcc' => ['nullable', 'digits_between:3,4'],
            'onboarding_status' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @var list<string> Card rates explicitly marked "N/A" (scheme not offered). */
    private array $notOffered = [];

    protected function prepareForValidation(): void
    {
        foreach (self::SCHEME_FIELDS as $field) {
            $value = $this->input($field);
            if (! is_string($value)) {
                continue;
            }

            $value = strtoupper(trim($value));
            if (in_array($value, ['N/A', 'NA', '-'], true)) {
                $this->notOffered[] = $field;
            }
            if (in_array($value, ['', 'N/A', 'NA', '-'], true)) {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * Issue #10: a live merchant must have a complete card tariff.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->input('status') !== MerchantStatus::Active->value || $this->boolean('is_test')) {
                    return;
                }

                // An explicit "N/A" is a decision (scheme not offered); an empty field is an omission.
                foreach (self::SCHEME_FIELDS as $field) {
                    if (! $this->filled($field) && ! in_array($field, $this->notOffered, true)) {
                        $validator->errors()->add($field, 'Required for an active merchant.');
                    }
                }
            },
        ];
    }
}

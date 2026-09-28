<?php

namespace App\Http\Requests\Admin;

use App\Enums\Currency;
use App\Enums\MerchantStatus;
use App\Enums\MidStatus;
use App\Enums\ProviderType;
use App\Models\Merchant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MerchantMidRequest extends FormRequest
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
            'mid' => ['required', 'string', 'max:64', Rule::unique('merchant_mids', 'mid')->ignore($this->route('mid'))],
            'provider_login' => ['nullable', 'string', 'max:64'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'label' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::enum(MidStatus::class)],
            'bank_provider_id' => ['nullable', Rule::exists('providers', 'id')->where('type', ProviderType::Bank->value)],
            'gate_provider_id' => ['nullable', Rule::exists('providers', 'id')->where('type', ProviderType::Gate->value)],
            'reports_start_date' => ['nullable', 'date'],
            'rolling_reserve_limit' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'processing_limit' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Issue #10: live MIDs of a live merchant need an acquirer to price costs.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var Merchant $merchant */
                $merchant = $this->route('merchant');

                if ($this->input('status') === MidStatus::Active->value
                    && $merchant->status === MerchantStatus::Active
                    && ! $merchant->is_test
                    && ! $this->filled('bank_provider_id')) {
                    $validator->errors()->add('bank_provider_id', 'An active MID needs an acquirer.');
                }
            },
        ];
    }
}

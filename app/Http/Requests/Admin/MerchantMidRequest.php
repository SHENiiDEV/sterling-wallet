<?php

namespace App\Http\Requests\Admin;

use App\Enums\Currency;
use App\Enums\MerchantStatus;
use App\Enums\MidStatus;
use App\Enums\ProviderType;
use App\Enums\TariffProduct;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Support\ProductScope;
use Illuminate\Database\Eloquent\Model;
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
            'bank_provider_id' => ['nullable', Rule::exists('providers', 'id')->where('type', ProviderType::Bank->value)->when(ProductScope::current(), fn ($rule, TariffProduct $p) => $rule->where('product', $p->value))],
            'gate_provider_id' => ['nullable', Rule::exists('providers', 'id')->where('type', ProviderType::Gate->value)->when(ProductScope::current(), fn ($rule, TariffProduct $p) => $rule->where('product', $p->value))],
            'gate_mid' => ['nullable', 'string', 'max:64'],
            'extra_gate_mids' => ['nullable', 'array', 'max:50'],
            'extra_gate_mids.*' => ['string', 'max:64'],
            'reports_start_date' => ['nullable', 'date'],
            'rolling_reserve_limit' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'processing_limit' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'processing_limit_visa' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'processing_limit_mastercard' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'daily_norm' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Typed as a comma / space / line separated list; the main account is not repeated.
        $extra = $this->input('extra_gate_mids');
        if (is_string($extra)) {
            $extra = preg_split('/[\s,;]+/', $extra, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (is_array($extra)) {
            $extra = array_values(array_diff(array_unique($extra), [trim((string) $this->input('gate_mid'))]));
            $this->merge(['extra_gate_mids' => $extra === [] ? null : $extra]);
        }
    }

    /**
     * A gateway account fronting two MIDs of the same currency cannot be told apart: every row lands on one of them.
     */
    private function checkGateAccountsAreFree(Validator $validator): void
    {
        $accounts = array_values(array_unique(array_filter([trim((string) $this->input('gate_mid')), ...(array) $this->input('extra_gate_mids', [])])));
        if ($accounts === [] || ! $this->filled('gate_provider_id') || ! $this->filled('currency')) {
            return;
        }

        $mid = $this->route('mid');
        $ignore = $mid instanceof Model ? $mid->getKey() : $mid;

        $others = MerchantMid::query()
            ->where('gate_provider_id', $this->input('gate_provider_id'))
            ->where('currency', $this->input('currency'))
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore))
            ->get();

        foreach ($others as $other) {
            $shared = array_values(array_intersect($accounts, $other->gateAccounts()));
            if ($shared !== []) {
                $validator->errors()->add('gate_mid', sprintf(
                    'Gateway account %s is already used by MID %s (same gateway and currency): the rows could not be told apart. Give each MID its own account.',
                    implode(', ', $shared),
                    $other->mid,
                ));

                return;
            }
        }
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

                $this->checkGateAccountsAreFree($validator);
            },
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Enums\Currency;
use App\Enums\OperationType;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An operation added by hand (e.g. one that landed on another terminal).
 */
class OperationRequest extends FormRequest
{
    public const FEE_FIELDS = ['eu_fee', 'non_eu_fee', 'ic_fee', 'ic_interchange', 'ic_scheme_fee', 'approve_fee', 'decline_fee', 'refund_fee'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $text = fn (int $max) => ['nullable', 'string', 'max:'.$max];

        return [
            'merchant_mid_id' => ['required', Rule::exists('merchant_mids', 'id')],
            'provider_id' => ['required', Rule::exists('providers', 'id')],
            'payment_id' => ['required', 'string', 'max:128'],
            'reference' => $text(128),
            'sp_id' => $text(128),
            'arn' => $text(64),
            'rrn' => $text(64),
            'approval_code' => $text(16),
            'card_mask' => $text(32),
            'card_bin' => ['nullable', 'digits_between:6,8'],
            'card_last4' => ['nullable', 'digits:4'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_name' => $text(255),
            'customer_account' => $text(64),
            'ips' => $text(16),
            'wallet' => $text(32),
            'region' => ['nullable', Rule::in(['eu', 'non_eu'])],
            'issuer_country' => ['nullable', 'string', 'size:2'],
            'issuer_name' => $text(255),
            'trn_type' => $text(16),
            'operation_type' => ['required', Rule::enum(OperationType::class)],
            'processing_code' => $text(64),
            'resolution' => $text(64),
            'report_date' => ['required', 'date'],
            'transaction_at' => ['nullable', 'date'],
            'processing_at' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'between:-100000000000,100000000000'],
            'currency' => ['required', Rule::enum(Currency::class)],
            ...array_fill_keys(self::FEE_FIELDS, ['nullable', 'numeric', 'min:0', 'max:1000000']),
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['issuer_country', 'currency'] as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => strtoupper((string) $this->input($field))]);
            }
        }
        if ($this->filled('customer_email')) {
            $this->merge(['customer_email' => strtolower(trim((string) $this->input('customer_email')))]);
        }
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $mid = MerchantMid::query()->find($this->integer('merchant_mid_id'));
                if (! in_array((int) $this->input('provider_id'), array_filter([$mid?->bank_provider_id, $mid?->gate_provider_id]), true)) {
                    $validator->errors()->add('provider_id', 'The provider must be the MID\'s acquirer or gateway.');
                }

                $current = $this->route('operation');
                $duplicate = MerchantOperation::query()
                    ->when($current instanceof MerchantOperation, fn ($q) => $q->whereKeyNot($current->id))
                    ->where('provider_id', $this->integer('provider_id'))
                    ->where('payment_id', $this->input('payment_id'))
                    ->where('trn_type', $this->filled('trn_type') ? $this->input('trn_type') : null)
                    ->where('amount', $this->input('amount'))
                    ->exists();
                if ($duplicate) {
                    $validator->errors()->add('payment_id', 'This provider already has an operation with this payment ID, type and amount.');
                }
            },
        ];
    }
}

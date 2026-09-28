<?php

namespace App\Http\Requests\Admin;

use App\Enums\WalletType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MerchantWalletRequest extends FormRequest
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
            'type' => ['required', Rule::enum(WalletType::class)],
            'label' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'string', 'max:16'],
            'network' => ['required', 'string', 'max:32'],
            'address' => ['required', 'string', 'max:255'],
            'seed_phrase' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }
}

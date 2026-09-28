<?php

namespace App\Http\Requests\Admin;

use App\Bots\ConnectorRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IntegrationAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Secrets are write-only: an empty field on edit keeps the stored value.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->route('account') === null;

        return [
            'provider_id' => ['required', Rule::exists('providers', 'id')],
            'connector' => ['required', Rule::in(array_keys(app(ConnectorRegistry::class)->all()))],
            'name' => ['required', 'string', 'max:255'],
            'login_url' => ['nullable', 'url', 'max:255'],
            'username' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'totp_secret' => ['nullable', 'string', 'max:128'],
            'settings' => ['nullable', 'array'],
            'mid_ids' => ['nullable', 'array'],
            'mid_ids.*' => ['integer', Rule::exists('merchant_mids', 'id')],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $settings = $this->input('settings');
        if (is_string($settings)) {
            $this->merge(['settings' => trim($settings) === '' ? null : (json_decode($settings, true) ?? 'invalid')]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['settings.array' => 'Settings must be a JSON object.'];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesToSave(): array
    {
        $data = $this->validated();
        foreach (['username', 'password', 'totp_secret'] as $secret) {
            if (($data[$secret] ?? null) === null || $data[$secret] === '') {
                if ($this->route('account') !== null && ! $this->boolean("clear_{$secret}")) {
                    unset($data[$secret]);
                } else {
                    $data[$secret] = null;
                }
            }
        }
        $data['mid_ids'] = ! empty($data['mid_ids']) ? array_map('intval', $data['mid_ids']) : null;

        return $data;
    }
}

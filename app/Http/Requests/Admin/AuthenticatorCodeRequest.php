<?php

namespace App\Http\Requests\Admin;

use App\Models\AuthenticatorCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AuthenticatorCodeRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'service' => ['nullable', 'string', 'max:255'],
            // Required for a new code; empty on an existing one keeps the stored secret.
            'secret' => [$this->route('code') ? 'nullable' : 'required', 'string', 'max:2000'],
            'digits' => ['nullable', 'integer', Rule::in([6, 7, 8])],
            'period' => ['nullable', 'integer', 'min:10', 'max:120'],
            'algorithm' => ['nullable', Rule::in(['sha1', 'sha256', 'sha512'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'links' => ['nullable', 'array', 'max:200'],
            'links.*.type' => ['required', Rule::in(array_keys(AuthenticatorCode::TARGETS))],
            'links.*.id' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function ($validator) {
            foreach ((array) $this->input('links', []) as $i => $link) {
                $model = AuthenticatorCode::TARGETS[$link['type'] ?? ''] ?? null;
                if ($model !== null && ! $model::query()->whereKey($link['id'] ?? 0)->exists()) {
                    $validator->errors()->add("links.{$i}.id", 'That record does not exist.');
                }
            }
        }];
    }
}

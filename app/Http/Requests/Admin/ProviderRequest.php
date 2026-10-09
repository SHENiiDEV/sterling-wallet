<?php

namespace App\Http\Requests\Admin;

use App\Bots\ConnectorRegistry;
use App\Enums\ProviderType;
use App\Models\Provider;
use App\Reports\Parsers\ParserRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProviderRequest extends FormRequest
{
    /** Operation fields a reconciliation key may use. */
    public const MATCH_FIELDS = ['card_bin', 'card_last4', 'amount', 'currency', 'email', 'arn', 'rrn', 'approval_code', 'payment_id'];

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
                'cost_wallet_percent', 'cost_settlement_fx_percent',
            ]), $percent),
            'cost_wallet_percent' => $nullablePercent,
            'cost_settlement_fx_percent' => $nullablePercent,
            ...array_fill_keys(Provider::FIXED_FIELDS, $fixed),
            'settlement_cycle' => ['nullable', 'string', 'max:32'],
            'rolling_reserve_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'report_format' => ['nullable', Rule::in(app(ParserRegistry::class)->formats())],
            'connector' => ['nullable', Rule::in(array_keys(app(ConnectorRegistry::class)->all()))],
            'timezone' => ['required', 'timezone:all'],
            'report_delay_days' => ['required', 'integer', 'min:0', 'max:30'],
            'matching' => ['nullable', 'array'],
            'matching.keys' => ['sometimes', 'array', 'min:1'],
            'matching.keys.*' => ['array', 'min:1'],
            'matching.keys.*.*' => ['string', Rule::in(self::MATCH_FIELDS)],
            'matching.window_minutes' => ['sometimes', 'integer', 'min:0', 'max:1440'],
            'matching.try_timezone_shift' => ['sometimes', 'boolean'],
            'matching.tie_breakers' => ['sometimes', 'array'],
            'matching.tie_breakers.*' => ['string', Rule::in(['email', 'closest_time'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['matching.array' => 'Matching rules must be a JSON object.'];
    }

    /**
     * The form sends matching rules as JSON text; an empty box means defaults.
     */
    protected function prepareForValidation(): void
    {
        // Optional surcharges: empty means none.
        foreach (['cost_wallet_percent', 'cost_settlement_fx_percent'] as $field) {
            if (! $this->filled($field)) {
                $this->merge([$field => 0]);
            }
        }

        $matching = $this->input('matching');
        if (is_string($matching)) {
            $decoded = trim($matching) === '' ? null : json_decode($matching, true);
            $this->merge(['matching' => $decoded ?? (trim($matching) === '' ? null : 'invalid')]);
        }
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Bots\BotSchedule;
use App\Bots\ConnectorRegistry;
use App\Bots\Connectors\CardaqExportConnector;
use App\Bots\Connectors\CorefyApiConnector;
use App\Models\IntegrationAccount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // The Corefy API bot has no login: its keys come with the commerce accounts of its MIDs.
            'username' => [Rule::requiredIf($creating && $this->input('connector') !== CorefyApiConnector::CODE), 'nullable', 'string', 'max:255'],
            'password' => [Rule::requiredIf($creating && $this->input('connector') !== CorefyApiConnector::CODE), 'nullable', 'string', 'max:255'],
            'api_keys' => ['nullable', 'array', 'max:200'],
            'api_keys.*.commerce_account' => ['required', 'string', 'max:64'],
            'api_keys.*.api_key' => ['nullable', 'string', 'max:255'],
            'totp_secret' => ['nullable', 'string', 'max:128'],
            'settings' => ['nullable', 'array'],
            'mid_ids' => ['nullable', 'array'],
            'mid_ids.*' => ['integer', Rule::exists('merchant_mids', 'id')],
            'is_active' => ['boolean'],
            'schedule_mode' => ['sometimes', Rule::in([BotSchedule::AUTO, BotSchedule::INTERVAL, BotSchedule::DAILY])],
            'schedule_every_minutes' => ['nullable', 'required_if:schedule_mode,interval', 'integer', 'min:5', 'max:10080'],
            'schedule_at' => ['nullable', 'required_if:schedule_mode,daily', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
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
     * A sub-rule on `settings` would make validated() drop every other
     * setting, so the mailbox is checked here instead.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $mail = $this->input('settings.mail_provider');

                if ($mail !== null && array_key_exists($mail, CardaqExportConnector::MAIL_PROVIDERS) === false) {
                    $validator->errors()->add('settings.mail_provider', 'The mailbox must be one of: '.implode(', ', array_keys(CardaqExportConnector::MAIL_PROVIDERS)).'.');
                }
            },
        ];
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
                if ($this->route('account') !== null && $this->boolean("clear_{$secret}") === false) {
                    unset($data[$secret]);
                } else {
                    $data[$secret] = null;
                }
            }
        }
        // Only the field of the chosen rhythm is kept.
        if (($data['schedule_mode'] ?? BotSchedule::AUTO) !== BotSchedule::INTERVAL) {
            $data['schedule_every_minutes'] = null;
        }
        if (($data['schedule_mode'] ?? BotSchedule::AUTO) !== BotSchedule::DAILY) {
            $data['schedule_at'] = null;
        }
        $data['mid_ids'] = empty($data['mid_ids']) ? null : array_map('intval', $data['mid_ids']);
        unset($data['api_keys']);

        // The API bot runs once a day, in the morning, for the day before.
        if (($data['connector'] ?? null) === CorefyApiConnector::CODE && ! $this->has('schedule_mode')) {
            $data['schedule_mode'] = BotSchedule::DAILY;
            $data['schedule_at'] = '07:00';
        }

        return $data;
    }

    /**
     * Saves the API keys typed for the commerce accounts of the account's MIDs (an empty field keeps the stored key)
     * and drops the keys of commerce accounts that are no longer among them.
     *
     * @return list<string> commerce accounts whose key was written
     */
    public function syncCredentials(IntegrationAccount $account): array
    {
        if ($account->connector !== CorefyApiConnector::CODE) {
            $account->credentials()->delete();

            return [];
        }

        $allowed = array_keys(app(CorefyApiConnector::class)->commerceAccountsOf($account));
        $written = [];
        foreach ((array) $this->input('api_keys', []) as $row) {
            $commerce = trim((string) ($row['commerce_account'] ?? ''));
            $secret = trim((string) ($row['api_key'] ?? ''));
            if ($secret !== '' && in_array($commerce, $allowed, true)) {
                $account->credentials()->updateOrCreate(['key' => $commerce], ['secret' => $secret]);
                $written[] = $commerce;
            }
        }
        $account->credentials()->whereNotIn('key', $allowed)->delete();

        return $written;
    }
}

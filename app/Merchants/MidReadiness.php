<?php

namespace App\Merchants;

use App\Enums\MerchantStatus;
use App\Enums\MidStatus;
use App\Enums\TariffProduct;
use App\Models\IntegrationAccount;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Reports\Generation\FxRateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What is missing for live merchants and terminals to be processed, reported,
 * planned and paid out without surprises. Each finding has a stable code so
 * the page can filter and count by it.
 */
class MidReadiness
{
    /** @var array<string, string> code => what it means, in the order they are shown */
    public const CODES = [
        'no_mids' => 'No terminals',
        'no_bank' => 'Terminal without an acquirer',
        'no_gate' => 'Terminal without a gateway',
        'no_limit' => 'No processing limit or daily norm',
        'no_fx' => 'No FX rate to the base currency',
        'no_bot' => 'No active bot for its provider',
        'tariff' => 'Card tariff incomplete',
        'no_schedule' => 'No payout schedule (T+N)',
        'no_wallet' => 'No payout wallet',
        'no_email' => 'No invoice e-mail',
        'no_client' => 'Not in a client',
    ];

    public function __construct(private FxRateResolver $fx) {}

    /**
     * @return Collection<int, array<string, mixed>> one entry per merchant that has something missing
     */
    public function merchants(): Collection
    {
        $today = CarbonImmutable::now(config('sterling.timezone'));
        $base = config('sterling.base_currency');
        $fx = [];

        $accounts = IntegrationAccount::query()->where('is_active', true)->get();
        $botCovers = fn (?int $providerId, MerchantMid $mid) => $providerId !== null && $accounts->contains(
            fn (IntegrationAccount $a) => $a->provider_id === $providerId && ($a->mid_ids === null || $a->mid_ids === [] || in_array($mid->id, array_map('intval', $a->mid_ids), true)),
        );

        return Merchant::query()
            ->with(['company.client', 'wallets', 'mids.bankProvider', 'mids.gateProvider'])
            ->whereIn('status', [MerchantStatus::Active, MerchantStatus::Onboarding])
            ->where('is_test', false)
            ->orderBy('name')
            ->get()
            ->map(function (Merchant $merchant) use ($today, $base, &$fx, $botCovers) {
                $merchantIssues = [];
                $add = function (array &$issues, string $code, ?string $detail = null) {
                    $issues[] = ['code' => $code, 'label' => self::CODES[$code], 'detail' => $detail];
                };

                $mids = $merchant->mids;
                if ($mids->isEmpty()) {
                    $add($merchantIssues, 'no_mids');
                }
                if ($merchant->company?->client === null) {
                    $add($merchantIssues, 'no_client');
                }
                if ($merchant->settlement_lag_days === null) {
                    $add($merchantIssues, 'no_schedule');
                }
                if (! $merchant->wallets->contains('is_active', true)) {
                    $add($merchantIssues, 'no_wallet');
                }
                if (blank($merchant->invoice_email)) {
                    $add($merchantIssues, 'no_email');
                }
                if ($mids->contains(fn (MerchantMid $m) => ($m->bankProvider?->product ?? TariffProduct::Card) === TariffProduct::Card) && ($missing = $merchant->missingTariffFields()) !== []) {
                    $add($merchantIssues, 'tariff', implode(', ', $missing));
                }

                $terminals = $mids->where('status', MidStatus::Active)->map(function (MerchantMid $mid) use ($today, $base, &$fx, $botCovers, $add) {
                    $issues = [];
                    $isCard = ($mid->bankProvider?->product ?? TariffProduct::Card) === TariffProduct::Card;

                    if ($mid->bank_provider_id === null) {
                        $add($issues, 'no_bank');
                    }
                    if ($isCard && $mid->bank_provider_id !== null && $mid->gate_provider_id === null) {
                        $add($issues, 'no_gate');
                    }
                    if ($mid->gate_provider_id !== null && $mid->processing_limit === null && $mid->processing_limit_visa === null && $mid->processing_limit_mastercard === null && $mid->daily_norm === null) {
                        $add($issues, 'no_limit');
                    }

                    $currency = $mid->currency->value;
                    if ($currency !== $base) {
                        $fx[$currency] ??= $this->fx->rate($currency, $base, $today) !== null;
                        if (! $fx[$currency]) {
                            $add($issues, 'no_fx', "{$currency}→{$base}");
                        }
                    }

                    foreach ([$mid->bankProvider, $mid->gateProvider] as $provider) {
                        if ($provider !== null && $provider->connector !== null && $provider->is_active && ! $botCovers($provider->id, $mid)) {
                            $add($issues, 'no_bot', $provider->name);
                        }
                    }

                    return $issues === [] ? null : [
                        'id' => $mid->id,
                        'mid' => $mid->mid,
                        'currency' => $currency,
                        'pair' => ($mid->bankProvider?->name ?? '—').($mid->gateProvider ? ' ↔ '.$mid->gateProvider->name : ''),
                        'issues' => $issues,
                    ];
                })->filter()->values()->all();

                return [
                    'public_id' => $merchant->public_id,
                    'name' => $merchant->name,
                    'company' => $merchant->company?->name,
                    'client' => $merchant->company?->client?->name,
                    'issues' => $merchantIssues,
                    'mids' => $terminals,
                ];
            })
            ->filter(fn (array $m) => $m['issues'] !== [] || $m['mids'] !== [])
            ->values();
    }

    /**
     * How many merchants and terminals have each kind of finding.
     *
     * @param  Collection<int, array<string, mixed>>  $merchants
     * @return array<string, int>
     */
    public function counts(Collection $merchants): array
    {
        $counts = array_fill_keys(array_keys(self::CODES), 0);

        foreach ($merchants as $merchant) {
            foreach ($merchant['issues'] as $issue) {
                $counts[$issue['code']]++;
            }
            foreach ($merchant['mids'] as $mid) {
                foreach ($mid['issues'] as $issue) {
                    $counts[$issue['code']]++;
                }
            }
        }

        return $counts;
    }
}

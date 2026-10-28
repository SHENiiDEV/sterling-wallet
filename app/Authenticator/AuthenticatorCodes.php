<?php

namespace App\Authenticator;

use App\Models\AuthenticatorCode;
use App\Models\AuthenticatorCodeLink;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\Settlement;
use App\Models\SettlementLine;
use Illuminate\Support\Collection;

/**
 * Finds the authenticator codes that belong next to a page: the ones linked to the client, company, merchant or
 * provider the page is about. Any page can ask with its own targets; the settlement page is the first to.
 */
class AuthenticatorCodes
{
    /**
     * Active codes linked to any of these targets.
     *
     * @param  list<array{0: string, 1: int}>  $targets  [type, id] pairs: client, company, merchant, provider
     * @return Collection<int, AuthenticatorCode>
     */
    public function linkedTo(array $targets): Collection
    {
        if ($targets === []) {
            return collect();
        }

        $ids = AuthenticatorCodeLink::query()
            ->where(function ($query) use ($targets) {
                foreach ($targets as [$type, $id]) {
                    $query->orWhere(fn ($q) => $q->where('target_type', $type)->where('target_id', $id));
                }
            })
            ->pluck('authenticator_code_id');

        return AuthenticatorCode::query()->whereIn('id', $ids)->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * Codes for a payout: those of the client, its companies and merchants, and of the providers behind the paid terminals
     * (the acquirer, the gateway and the crypto provider, e.g. Oxen).
     *
     * @return Collection<int, AuthenticatorCode>
     */
    public function forSettlement(Settlement $settlement): Collection
    {
        $merchants = Merchant::query()->with('company')->whereIn('id', $settlement->merchantIds())->get();
        $reportMidIds = SettlementLine::query()
            ->where('settlement_id', $settlement->id)
            ->whereNotNull('daily_report_task_id')
            ->join('daily_report_tasks', 'daily_report_tasks.id', '=', 'settlement_lines.daily_report_task_id')
            ->pluck('daily_report_tasks.merchant_mid_id');
        $mids = MerchantMid::query()->whereIn('id', $reportMidIds)->get(['bank_provider_id', 'gate_provider_id']);

        $targets = [];
        foreach ($merchants as $merchant) {
            $targets[] = ['merchant', $merchant->id];
            if ($merchant->company_id !== null) {
                $targets[] = ['company', $merchant->company_id];
            }
            if ($merchant->crypto_provider_id !== null) {
                $targets[] = ['provider', $merchant->crypto_provider_id];
            }
            if ($merchant->company?->client_id !== null) {
                $targets[] = ['client', $merchant->company->client_id];
            }
        }
        if ($settlement->client_id !== null) {
            $targets[] = ['client', $settlement->client_id];
        }
        foreach ($mids as $mid) {
            foreach ([$mid->bank_provider_id, $mid->gate_provider_id] as $providerId) {
                if ($providerId !== null) {
                    $targets[] = ['provider', $providerId];
                }
            }
        }

        return $this->linkedTo(array_values(array_unique($targets, SORT_REGULAR)));
    }

    /**
     * What a page may know about codes: names and what they are linked to, never the secret.
     *
     * @param  Collection<int, AuthenticatorCode>  $codes
     * @return list<array<string, mixed>>
     */
    public function present(Collection $codes): array
    {
        $codes->load('links');
        $names = $this->names($codes->flatMap->links);

        return $codes->map(fn (AuthenticatorCode $code) => [
            'id' => $code->id,
            'name' => $code->name,
            'service' => $code->service,
            'notes' => $code->notes,
            'is_active' => $code->is_active,
            'digits' => $code->digits,
            'period' => $code->period,
            'links' => $code->links->map(fn (AuthenticatorCodeLink $link) => [
                'type' => $link->target_type,
                'id' => $link->target_id,
                'name' => $names[$link->target_type.'|'.$link->target_id] ?? "#{$link->target_id}",
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, AuthenticatorCodeLink>  $links
     * @return array<string, string> "type|id" => display name
     */
    private function names(Collection $links): array
    {
        $names = [];
        foreach (AuthenticatorCode::TARGETS as $type => $model) {
            $ids = $links->where('target_type', $type)->pluck('target_id')->unique()->all();
            if ($ids === []) {
                continue;
            }
            foreach ($model::query()->whereIn('id', $ids)->get(['id', 'name']) as $row) {
                $names[$type.'|'.$row->id] = $row->name;
            }
        }

        return $names;
    }
}

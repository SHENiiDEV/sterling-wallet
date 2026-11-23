<?php

namespace App\Merchants;

use App\Enums\MidStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\ReserveLedgerEntry;
use Illuminate\Support\Collection;

/**
 * Clients as folders: client → companies → merchants → MIDs.
 * Companies without a client (and merchants without a company) sit in the "No client" folder.
 */
final class MerchantFolders
{
    public const NONE = 'none';

    /** @var array<string, list<string>>|null gateway|currency|account => MID numbers that use it */
    private ?array $gateUsage = null;

    /**
     * @return list<array<string, mixed>>
     */
    public function clients(): array
    {
        $folders = Client::query()->orderBy('name')->get()->map(fn (Client $client) => $this->summary(
            (string) $client->id,
            $client->name,
            $this->companies($client->id),
            $this->orphans($client->id),
        ))->all();

        $none = $this->summary(self::NONE, 'No client', $this->companies(null), $this->orphans(null));

        return $none['merchants'] > 0 || $none['companies'] > 0 ? [...$folders, $none] : $folders;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function client(string $key): ?array
    {
        if ($key === self::NONE) {
            $id = null;
            $name = 'No client';
        } else {
            $client = Client::query()->find($key);
            if ($client === null) {
                return null;
            }
            $id = $client->id;
            $name = $client->name;
        }

        $companies = $this->companies($id);
        $orphans = $this->orphans($id);
        $folder = $this->summary($key, $name, $companies, $orphans);

        $groups = $companies->map(fn (Company $company) => [
            'id' => $company->id,
            'name' => $company->name,
            'parent' => $company->parent?->name,
            'merchants' => $company->merchants->map(fn (Merchant $m) => $this->merchant($m))->values()->all(),
        ]);
        if ($orphans->isNotEmpty()) {
            $groups->push(['id' => null, 'name' => 'No company', 'parent' => null, 'merchants' => $orphans->map(fn (Merchant $m) => $this->merchant($m))->values()->all()]);
        }

        return [...$folder, 'reserve' => isset($client) ? $this->reserve($client, $companies) : null, 'groups' => $groups->values()->all()];
    }

    /**
     * What the client's reserve holds now: per currency, with the agreed pool when one is set, and who holds how much.
     *
     * @param  Collection<int, Company>  $companies
     * @return array<string, mixed>
     */
    private function reserve(Client $client, Collection $companies): array
    {
        $merchants = $companies->flatMap->merchants->keyBy('id');
        $rows = ReserveLedgerEntry::query()
            ->whereIn('merchant_id', $merchants->keys())
            ->selectRaw('merchant_id, currency, sum(amount) as total')
            ->groupBy('merchant_id', 'currency')
            ->get();

        $limit = $client->reserve_pool_enabled ? $client->reserve_pool_limit : null;

        return [
            'pool' => $client->reserve_pool_enabled,
            'limit' => $limit,
            'totals' => $rows->groupBy('currency')->map(fn (Collection $r, string $currency) => [
                'currency' => $currency,
                'amount' => number_format((float) $r->sum('total'), 2, '.', ''),
            ])->sortKeys()->values()->all(),
            'by_merchant' => $rows->groupBy('merchant_id')->map(fn (Collection $r, int|string $id) => [
                'merchant' => $merchants->get((int) $id)?->name,
                'company' => $companies->first(fn (Company $c) => $c->merchants->contains('id', (int) $id))?->name,
                'amounts' => $r->map(fn ($e) => ['currency' => $e->currency, 'amount' => number_format((float) $e->total, 2, '.', '')])->sortBy('currency')->values()->all(),
            ])->sortBy('merchant')->values()->all(),
        ];
    }

    /**
     * @return Collection<int, Company>
     */
    private function companies(?int $clientId): Collection
    {
        return Company::query()
            ->with(['parent:id,name', 'merchants' => fn ($q) => $q->orderBy('name'), 'merchants.mids.bankProvider:id,name', 'merchants.mids.gateProvider:id,name'])
            ->where('client_id', $clientId)
            ->orderBy('name')
            ->get();
    }

    /**
     * Merchants that have no company: only in the "No client" folder.
     *
     * @return Collection<int, Merchant>
     */
    private function orphans(?int $clientId): Collection
    {
        if ($clientId !== null) {
            return collect();
        }

        return Merchant::query()->whereNull('company_id')->with(['mids.bankProvider:id,name', 'mids.gateProvider:id,name'])->orderBy('name')->get();
    }

    /**
     * @param  Collection<int, Company>  $companies
     * @param  Collection<int, Merchant>  $orphans
     * @return array<string, mixed>
     */
    private function summary(string $key, string $name, Collection $companies, Collection $orphans): array
    {
        $merchants = $companies->flatMap->merchants->concat($orphans);
        $mids = $merchants->flatMap->mids;

        return [
            'key' => $key,
            'name' => $name,
            'companies' => $companies->count(),
            'merchants' => $merchants->count(),
            'mids' => $mids->count(),
            'active_mids' => $mids->where('status', MidStatus::Active)->count(),
            'currencies' => $mids->map(fn (MerchantMid $m) => $m->currency->value)->unique()->sort()->values()->all(),
            'preview' => $merchants->take(3)->pluck('name')->values()->all(),
            'mid_list' => $mids->sortBy('mid')->take(8)->map(fn (MerchantMid $m) => ['mid' => $m->mid, 'currency' => $m->currency->value, 'status' => $m->status->value, 'conflict' => $this->conflicts($m) !== []])->values()->all(),
        ];
    }

    /**
     * Other MIDs of the same gateway and currency that share a gateway account with this one.
     *
     * @return list<string>
     */
    private function conflicts(MerchantMid $mid): array
    {
        $this->gateUsage ??= MerchantMid::query()->whereNotNull('gate_provider_id')->get()
            ->reduce(function (array $usage, MerchantMid $m) {
                foreach ($m->gateAccounts() as $account) {
                    $usage["{$m->gate_provider_id}|{$m->currency->value}|{$account}"][] = $m->mid;
                }

                return $usage;
            }, []);

        $others = [];
        foreach ($mid->gateAccounts() as $account) {
            $others = [...$others, ...array_diff($this->gateUsage["{$mid->gate_provider_id}|{$mid->currency->value}|{$account}"] ?? [], [$mid->mid])];
        }

        return array_values(array_unique($others));
    }

    /**
     * @return array<string, mixed>
     */
    private function merchant(Merchant $merchant): array
    {
        return [
            'id' => $merchant->id,
            'public_id' => $merchant->public_id,
            'name' => $merchant->name,
            'website' => $merchant->website,
            'status' => $merchant->status->value,
            'status_label' => $merchant->status->label(),
            'mids' => $merchant->mids->sortBy('currency')->map(fn (MerchantMid $mid) => [
                'id' => $mid->id,
                'mid' => $mid->mid,
                'currency' => $mid->currency->value,
                'status' => $mid->status->value,
                'status_label' => $mid->status->label(),
                'bank' => $mid->bankProvider?->name,
                'gate' => $mid->gateProvider?->name,
                'gate_accounts' => count($mid->gateAccounts()),
                'gate_mid' => $mid->gateAccounts(),
                'gate_conflicts' => $this->conflicts($mid),
            ])->values()->all(),
        ];
    }
}

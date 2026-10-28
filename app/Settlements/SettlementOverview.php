<?php

namespace App\Settlements;

use App\Enums\SettlementLineType;
use App\Enums\SettlementStatus;
use App\Models\Client;
use App\Models\DailyReportTask;
use App\Models\MerchantAcquirer;
use App\Models\MerchantCryptoWallet;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who a settlement pays and for what: the client with its requisites, the wallet the money goes to, and under
 * them the companies, merchants and terminals (with their acquirer and gateway) whose reports it carries.
 */
class SettlementOverview
{
    /**
     * @return array{client: array<string, mixed>|null, wallet: array<string, mixed>|null, companies: list<array<string, mixed>>, other_lines: list<array<string, mixed>>}
     */
    public function for(Settlement $settlement): array
    {
        $settlement->loadMissing(['client', 'merchant.company.client', 'wallet.merchant:id,name', 'lines.dailyReport']);

        $reportLines = $settlement->lines->where('type', SettlementLineType::Report)->filter(fn (SettlementLine $l) => $l->daily_report_task_id !== null);
        $reports = DailyReportTask::query()
            ->with(['merchantMid.bankProvider:id,name', 'merchantMid.gateProvider:id,name', 'merchant.company.client'])
            ->whereIn('id', $reportLines->pluck('daily_report_task_id'))
            ->get()
            ->keyBy('id');

        $acquirers = MerchantAcquirer::query()
            ->whereIn('merchant_id', $reports->pluck('merchant_id')->unique())
            ->get()
            ->keyBy(fn (MerchantAcquirer $a) => $a->merchant_id.'|'.$a->provider_id);

        $client = $settlement->client ?? $settlement->merchant->company?->client;

        return [
            'client' => $client ? $this->client($client) : null,
            'wallet' => $settlement->wallet ? $this->wallet($settlement->wallet) : null,
            'companies' => $this->companies($reportLines, $reports, $acquirers),
            // Charges, adjustments and reserve releases are not tied to a terminal.
            'other_lines' => $settlement->lines
                ->reject(fn (SettlementLine $l) => $l->type === SettlementLineType::Report)
                ->map(fn (SettlementLine $l) => ['type' => $l->type->label(), 'description' => $l->description, 'currency' => $l->currency, 'amount' => $l->amount])
                ->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function client(Client $client): array
    {
        $settlements = Settlement::query()->where('client_id', $client->id)->where('status', '!=', SettlementStatus::Cancelled->value);

        return [
            'id' => $client->id,
            'name' => $client->name,
            'invoice_name' => $client->invoiceName(),
            'invoice_address' => $client->invoice_address,
            'invoice_email' => $client->invoice_email,
            'notes' => $client->notes,
            'companies' => $client->companies()->withCount('merchants')->orderBy('name')->get()
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'merchants' => $c->merchants_count])->values()->all(),
            'portal_users' => User::query()->where('client_id', $client->id)->orderBy('name')->get()
                ->map(fn (User $u) => ['name' => $u->name, 'email' => $u->email, 'is_active' => $u->is_active, 'last_login_at' => $u->last_login_at?->toIso8601String()])->all(),
            'settlements' => [
                'count' => (clone $settlements)->count(),
                'settled' => (clone $settlements)->where('status', SettlementStatus::Settled->value)->count(),
                'last_settled_at' => (clone $settlements)->where('status', SettlementStatus::Settled->value)->max('settled_at'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function wallet(MerchantCryptoWallet $wallet): array
    {
        return [
            'label' => $wallet->label ?: $wallet->type->label(),
            'type' => $wallet->type->label(),
            'currency' => $wallet->currency,
            'network' => $wallet->network,
            'address' => $wallet->address,
            'owner' => $wallet->merchant?->name,
            'notes' => $wallet->notes,
        ];
    }

    /**
     * @param  Collection<int, SettlementLine>  $lines
     * @param  Collection<int, DailyReportTask>  $reports
     * @param  Collection<string, MerchantAcquirer>  $acquirers
     * @return list<array<string, mixed>>
     */
    private function companies(Collection $lines, Collection $reports, Collection $acquirers): array
    {
        $byCompany = [];

        foreach ($lines as $line) {
            $report = $reports->get($line->daily_report_task_id);
            $mid = $report?->merchantMid;
            if ($report === null || $mid === null) {
                continue;
            }

            $merchant = $report->merchant;
            $key = (string) ($merchant->company_id ?? 'none');

            $byCompany[$key]['name'] ??= $merchant->company?->name ?? 'No company';
            $byCompany[$key]['merchants'][$merchant->id] ??= [
                'public_id' => $merchant->public_id,
                'name' => $merchant->name,
                'website' => $merchant->website,
                'lag_days' => $merchant->settlement_lag_days,
                'mids' => [],
            ];

            $acquirer = $acquirers->get($merchant->id.'|'.$mid->bank_provider_id);
            $row = $byCompany[$key]['merchants'][$merchant->id]['mids'][$mid->id] ?? [
                'mid' => $mid->mid,
                'label' => $mid->label,
                'currency' => $report->currency,
                'bank' => $mid->bankProvider?->name,
                'bank_status' => $acquirer?->status->label(),
                'gate' => $mid->gateProvider?->name,
                'reports' => 0,
                'amount' => '0',
                'from' => null,
                'to' => null,
            ];

            $date = $report->report_date->toDateString();
            $row['reports']++;
            $row['amount'] = (string) ((float) $row['amount'] + (float) $line->amount);
            $row['from'] = $row['from'] === null || $date < $row['from'] ? $date : $row['from'];
            $row['to'] = $row['to'] === null || $date > $row['to'] ? $date : $row['to'];
            $byCompany[$key]['merchants'][$merchant->id]['mids'][$mid->id] = $row;
        }

        return collect($byCompany)->map(fn (array $company) => [
            'name' => $company['name'],
            'merchants' => collect($company['merchants'])->map(fn (array $m) => [...$m, 'mids' => array_values($m['mids'])])->values()->all(),
        ])->sortBy('name')->values()->all();
    }
}

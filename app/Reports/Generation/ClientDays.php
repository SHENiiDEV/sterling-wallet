<?php

namespace App\Reports\Generation;

use App\Enums\ReportStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use Illuminate\Support\Collection;

/**
 * A client's month, day by day: how many reports are ready, the net payout
 * per currency and the same per company. Feeds the client page of the admin
 * and of the merchant portal; never contains costs or profit.
 */
class ClientDays
{
    /**
     * @param  Collection<int, Company>|null  $companies  only these companies of the client (default: all)
     * @return list<array{date: string, completed: int, waiting: int, payout: array<string, string>, companies: list<array{id: int, name: string, reports: int, payout: array<string, string>}>}>
     */
    public function forMonth(Client $client, string $month, ?Collection $companies = null): array
    {
        $companies ??= $client->companies;
        $merchantIds = Merchant::query()->whereIn('company_id', $companies->pluck('id'))->pluck('id');

        $tasks = DailyReportTask::query()
            ->with('merchant:id,name,company_id')
            ->whereIn('merchant_id', $merchantIds)
            ->where('report_date', 'like', "{$month}-%")
            ->get();

        return $tasks->groupBy(fn (DailyReportTask $t) => $t->report_date->toDateString())
            ->sortKeysDesc()
            ->map(function (Collection $group, string $date) use ($companies) {
                $done = $group->where('status', ReportStatus::Completed);

                return [
                    'date' => $date,
                    'completed' => $done->count(),
                    'waiting' => $group->count() - $done->count(),
                    'payout' => $this->payout($done),
                    'companies' => $companies->map(function (Company $company) use ($done) {
                        $own = $done->filter(fn (DailyReportTask $t) => $t->merchant?->company_id === $company->id);

                        return ['id' => $company->id, 'name' => $company->name, 'reports' => $own->count(), 'payout' => $this->payout($own)];
                    })->filter(fn (array $c) => $c['reports'] > 0)->values()->all(),
                ];
            })->values()->all();
    }

    /**
     * The latest month that has any report, for a first look.
     *
     * @param  Collection<int, Company>|null  $companies
     */
    public function latestMonth(Client $client, ?Collection $companies = null): ?string
    {
        $companies ??= $client->companies;
        $latest = DailyReportTask::query()
            ->whereIn('merchant_id', Merchant::query()->whereIn('company_id', $companies->pluck('id'))->select('id'))
            ->max('report_date');

        return $latest === null ? null : substr((string) $latest, 0, 7);
    }

    /**
     * @param  Collection<int, DailyReportTask>  $tasks
     * @return array<string, string>
     */
    private function payout(Collection $tasks): array
    {
        return $tasks->groupBy('currency')->map(fn (Collection $g) => number_format((float) $g->sum('net_payout'), 2, '.', ''))->sortKeys()->all();
    }
}

<?php

namespace App\Settlements;

use App\Models\DailyReportTask;
use App\Models\Merchant;
use Illuminate\Support\Collection;

/**
 * Merchants that have unpaid reports which are due (or overdue) for
 * settlement today, by their own T+N schedule. One place to ask, so the
 * Settlements page, the dashboard and later a Telegram notification agree.
 */
class DueSettlements
{
    public function __construct(private SettlementService $settlements, private SettlementSchedule $schedule) {}

    /**
     * @return Collection<int, array{merchant: Merchant, reports: list<array<string, mixed>>, overdue: int}>
     */
    public function merchants(): Collection
    {
        $today = $this->schedule->today();

        return $this->settlements->availableReportsFor(null)
            ->load('merchant:id,public_id,name,company_id,settlement_lag_days')
            ->map(fn (DailyReportTask $r) => ['report' => $r, 'state' => $this->schedule->state($r, $today)])
            ->filter(fn (array $x) => in_array($x['state'], [SettlementSchedule::DUE, SettlementSchedule::OVERDUE], true))
            ->groupBy(fn (array $x) => $x['report']->merchant_id)
            ->map(fn (Collection $group) => [
                'merchant' => $group->first()['report']->merchant,
                'overdue' => $group->where('state', SettlementSchedule::OVERDUE)->count(),
                'reports' => $group->map(fn (array $x) => [
                    'id' => $x['report']->id,
                    'report_date' => $x['report']->report_date->toDateString(),
                    'currency' => $x['report']->currency,
                    'net_payout' => $x['report']->getRawOriginal('net_payout'),
                    'state' => $x['state'],
                ])->values()->all(),
            ])
            ->sortBy(fn (array $row) => $row['merchant']->name)
            ->values();
    }
}

<?php

namespace App\Reports\Pending;

use App\Models\DailyReportSource;
use App\Models\MerchantMid;
use App\Models\Provider;
use App\Reports\ReportDateResolver;
use App\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which report periods a provider still owes, per MID: periods that are
 * ready to fetch (report_delay_days passed) but have no source row yet.
 */
class PendingReportFinder
{
    public function __construct(private ReportDateResolver $dates) {}

    /**
     * @param  Collection<int, MerchantMid>|null  $mids  limit to these MIDs (default: all MIDs the provider serves)
     * @return Collection<int, array{mid: MerchantMid, periods: list<ReportPeriod>}>
     */
    public function find(Provider $provider, ?Collection $mids = null, ?CarbonImmutable $today = null): Collection
    {
        $today ??= CarbonImmutable::now(config('sterling.timezone'))->startOfDay();
        $lookback = $today->subDays((int) config('sterling.reports.pending_lookback_days'));

        $mids ??= $provider->servedMids()->where('status', '!=', 'inactive')->get();
        if ($mids->isEmpty()) {
            return collect();
        }

        $received = DailyReportSource::query()
            ->join('daily_report_tasks', 'daily_report_tasks.id', '=', 'daily_report_sources.daily_report_task_id')
            ->where('daily_report_sources.provider_id', $provider->id)
            ->whereIn('daily_report_tasks.merchant_mid_id', $mids->modelKeys())
            ->where('daily_report_tasks.report_date', '>=', $lookback->toDateString())
            ->get(['daily_report_tasks.merchant_mid_id', 'daily_report_tasks.report_date'])
            ->map(fn ($row) => $row->merchant_mid_id.'|'.CarbonImmutable::parse($row->report_date)->toDateString())
            ->flip();

        return $mids->map(function (MerchantMid $mid) use ($provider, $lookback, $today, $received) {
            $start = $mid->reports_start_date && $mid->reports_start_date->greaterThan($lookback) ? $mid->reports_start_date : $lookback;

            $periods = array_values(array_filter(
                $this->dates->readyPeriods($provider, $start, $today),
                fn (ReportPeriod $p) => ! $received->has($mid->id.'|'.$p->reportDate->toDateString()),
            ));

            return ['mid' => $mid, 'periods' => $periods];
        })->filter(fn (array $entry) => $entry['periods'] !== [])->values();
    }
}

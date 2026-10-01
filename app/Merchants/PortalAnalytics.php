<?php

namespace App\Merchants;

use App\Enums\ReportStatus;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Charts for the merchant portal, in the base currency (each report is
 * converted at its own frozen rate) so companies and MIDs in different
 * currencies can be compared and added up. Sales and payout only — never
 * our costs or profit.
 */
class PortalAnalytics
{
    /**
     * @param  Collection<int, Merchant>  $merchants  with `company` loaded
     * @return array<string, mixed>
     */
    public function for(Collection $merchants, ?CarbonImmutable $today = null, int $months = 6): array
    {
        $today ??= CarbonImmutable::now(config('sterling.timezone'));
        $from = $today->startOfMonth()->subMonths($months - 1);

        $rows = DailyReportTask::query()
            ->whereIn('merchant_id', $merchants->pluck('id'))
            ->where('status', ReportStatus::Completed)
            ->whereBetween('report_date', [min($from->toDateString(), $today->subDays(29)->toDateString()), $today->toDateString()])
            ->get(['merchant_id', 'report_date', 'sales_count', 'turnover_base', 'net_payout', 'fx_rate']);

        $byMonth = [];
        for ($m = $from; $m->lte($today); $m = $m->addMonth()) {
            $byMonth[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'label' => $m->format('M Y'), 'turnover' => BigDecimal::zero(), 'payout' => BigDecimal::zero(), 'sales' => 0];
        }

        $companyOf = $merchants->mapWithKeys(fn (Merchant $m) => [$m->id => $m->company_id]);
        $names = $merchants->mapWithKeys(fn (Merchant $m) => [$m->company_id => $m->company?->name ?? 'No company']);
        $thisMonth = $today->format('Y-m');
        // Companies are compared over the last 30 days, so the table is never empty early in a month.
        $recentFrom = $today->subDays(29)->toDateString();
        $byCompany = [];
        $byMerchant = [];

        foreach ($rows as $row) {
            $month = $row->report_date->format('Y-m');
            $turnover = BigDecimal::of((string) ($row->getRawOriginal('turnover_base') ?? 0));
            $payout = BigDecimal::of((string) ($row->getRawOriginal('net_payout') ?? 0))->multipliedBy((string) ($row->getRawOriginal('fx_rate') ?? 1));

            if (isset($byMonth[$month])) {
                $byMonth[$month]['turnover'] = $byMonth[$month]['turnover']->plus($turnover);
                $byMonth[$month]['payout'] = $byMonth[$month]['payout']->plus($payout);
                $byMonth[$month]['sales'] += (int) $row->sales_count;
            }

            if ($row->report_date->toDateString() >= $recentFrom) {
                $company = $companyOf[$row->merchant_id] ?? 0;
                $byCompany[$company] ??= ['turnover' => BigDecimal::zero(), 'payout' => BigDecimal::zero(), 'sales' => 0];
                $byCompany[$company]['turnover'] = $byCompany[$company]['turnover']->plus($turnover);
                $byCompany[$company]['payout'] = $byCompany[$company]['payout']->plus($payout);
                $byCompany[$company]['sales'] += (int) $row->sales_count;

                $byMerchant[$row->merchant_id] = ($byMerchant[$row->merchant_id] ?? BigDecimal::zero())->plus($turnover);
            }
        }

        $round = fn (BigDecimal $v) => (float) (string) $v->toScale(2, RoundingMode::HalfUp);
        $previous = $byMonth[$today->subMonth()->format('Y-m')] ?? null;
        $current = $byMonth[$thisMonth];

        return [
            'currency' => config('sterling.base_currency'),
            'months' => array_values(array_map(fn (array $m) => [
                'month' => $m['month'],
                'label' => $m['label'],
                'turnover' => $round($m['turnover']),
                'payout' => $round($m['payout']),
                'sales' => $m['sales'],
            ], $byMonth)),
            'this_month' => [
                'turnover' => $round($current['turnover']),
                'payout' => $round($current['payout']),
                'sales' => $current['sales'],
                // Change against the same point of last month is not known; compare to last month's total.
                'previous_turnover' => $previous ? $round($previous['turnover']) : null,
            ],
            'companies' => collect($merchants->pluck('company_id')->unique())
                ->map(fn ($id) => [
                    'id' => $id,
                    'name' => $names[$id] ?? 'No company',
                    'merchants' => $merchants->where('company_id', $id)->count(),
                    'turnover' => $round($byCompany[$id]['turnover'] ?? BigDecimal::zero()),
                    'payout' => $round($byCompany[$id]['payout'] ?? BigDecimal::zero()),
                    'sales' => $byCompany[$id]['sales'] ?? 0,
                ])
                ->sortByDesc('turnover')->values()->all(),
            'merchant_turnover' => $merchants->mapWithKeys(fn (Merchant $m) => [$m->public_id => $round($byMerchant[$m->id] ?? BigDecimal::zero())])->all(),
        ];
    }
}

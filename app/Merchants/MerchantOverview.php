<?php

namespace App\Merchants;

use App\Enums\ReportStatus;
use App\Enums\SettlementStatus;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use App\Models\ReserveLedgerEntry;
use App\Models\Settlement;
use App\Settlements\SettlementService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The money picture of one or more merchants, as the merchant sees it:
 * this month's sales and payout, what is earned but not paid yet, reserve
 * held, the last payout and a daily series. No provider cost or profit.
 * Amounts are kept per currency — MIDs of one company can differ.
 */
class MerchantOverview
{
    public function __construct(private SettlementService $settlements) {}

    /**
     * @param  Collection<int, Merchant>  $merchants
     * @return array<string, mixed>
     */
    public function for(Collection $merchants, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now(config('sterling.timezone'));
        $ids = $merchants->pluck('id');
        $monthStart = $today->startOfMonth()->toDateString();

        $month = DailyReportTask::query()
            ->whereIn('merchant_id', $ids)
            ->where('status', ReportStatus::Completed)
            ->whereBetween('report_date', [$monthStart, $today->toDateString()])
            ->get(['currency', 'sales_count', 'turnover', 'net_payout']);

        $unpaid = [];
        foreach ($merchants as $merchant) {
            foreach ($this->settlements->availableReports($merchant) as $report) {
                $unpaid[$report->currency] = ($unpaid[$report->currency] ?? BigDecimal::zero())->plus((string) $report->getRawOriginal('net_payout'));
            }
            foreach ($this->settlements->availableReleases($merchant) as $release) {
                $unpaid[$release->currency] = ($unpaid[$release->currency] ?? BigDecimal::zero())->plus(BigDecimal::of((string) $release->amount)->abs());
            }
        }

        $reserve = ReserveLedgerEntry::query()
            ->whereIn('merchant_id', $ids)
            ->selectRaw('currency, sum(amount) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $lastPayout = Settlement::query()
            ->whereIn('merchant_id', $ids)
            ->where('status', SettlementStatus::Settled)
            ->latest('settled_at')
            ->first(['id', 'number', 'total_payout', 'payout_currency', 'settled_at']);

        $from = $today->subDays(29)->toDateString();
        $daily = DailyReportTask::query()
            ->whereIn('merchant_id', $ids)
            ->where('status', ReportStatus::Completed)
            ->whereBetween('report_date', [$from, $today->toDateString()])
            ->selectRaw('report_date, sum(turnover_base) as total')
            ->groupBy('report_date')
            ->pluck('total', 'report_date');

        $series = [];
        for ($day = CarbonImmutable::parse($from); $day->lte($today); $day = $day->addDay()) {
            $key = $day->toDateString();
            $value = $daily->first(fn ($v, $date) => substr((string) $date, 0, 10) === $key);
            $series[] = ['date' => $key, 'value' => round((float) ($value ?? 0), 2)];
        }

        return [
            'month' => $today->format('F Y'),
            'sales' => (int) $month->sum('sales_count'),
            'turnover' => $this->byCurrency($month, 'turnover'),
            'payout' => $this->byCurrency($month, 'net_payout'),
            'unpaid' => $this->format($unpaid),
            'reserve' => $this->format($reserve->map(fn ($v) => BigDecimal::of((string) $v))->all()),
            'last_payout' => $lastPayout ? [
                'number' => $lastPayout->number,
                'amount' => (string) $lastPayout->total_payout,
                'currency' => $lastPayout->payout_currency,
                'date' => $lastPayout->settled_at?->toDateString(),
            ] : null,
            'daily' => $series,
            'base_currency' => config('sterling.base_currency'),
        ];
    }

    /**
     * @param  Collection<int, DailyReportTask>  $reports
     * @return list<array{currency: string, amount: string}>
     */
    private function byCurrency(Collection $reports, string $field): array
    {
        $sums = [];
        foreach ($reports as $report) {
            $sums[$report->currency] = ($sums[$report->currency] ?? BigDecimal::zero())->plus((string) $report->getRawOriginal($field));
        }

        return $this->format($sums);
    }

    /**
     * @param  array<string, BigDecimal>  $sums
     * @return list<array{currency: string, amount: string}>
     */
    private function format(array $sums): array
    {
        ksort($sums);

        return array_values(array_map(
            fn (string $currency) => ['currency' => $currency, 'amount' => (string) $sums[$currency]->toScale(2, RoundingMode::HalfUp)],
            array_keys(array_filter($sums, fn (BigDecimal $v) => ! $v->isZero())),
        ));
    }
}

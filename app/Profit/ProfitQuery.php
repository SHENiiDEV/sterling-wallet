<?php

namespace App\Profit;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Enums\SettlementLineType;
use App\Enums\SettlementStatus;
use App\Models\DailyReportTask;
use App\Models\MerchantOperation;
use App\Models\ReserveLedgerEntry;
use App\Models\Settlement;
use App\Models\SettlementLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Profit figures from completed daily reports, in the base currency (EUR)
 * at each report's frozen rate. Everything is a SQL aggregate.
 */
class ProfitQuery
{
    private const REVENUE = '(daily_report_tasks.total_merchant_fee + daily_report_tasks.conversion_fee) * coalesce(daily_report_tasks.fx_rate, 0)';

    private const COST = 'daily_report_tasks.total_provider_cost * coalesce(daily_report_tasks.fx_rate, 0)';

    /**
     * @return Builder<DailyReportTask>
     */
    private function reports(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DailyReportTask::query()
            ->where('daily_report_tasks.status', ReportStatus::Completed)
            ->whereBetween('daily_report_tasks.report_date', [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * @return array{turnover: float, revenue: float, cost: float, net_profit: float, margin: float|null, reports: int, sales: int}
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = $this->reports($from, $to)->toBase()->selectRaw(
            'coalesce(sum(turnover_base), 0) as turnover, coalesce(sum('.self::REVENUE.'), 0) as revenue, '
            .'coalesce(sum('.self::COST.'), 0) as cost, coalesce(sum(net_profit_base), 0) as net_profit, '
            .'count(*) as reports, coalesce(sum(sales_count), 0) as sales',
        )->first();

        // Settlement charges are pure revenue (no provider cost), booked
        // on the settlement in the base currency.
        $settlementFees = $this->settlementFees($from, $to);

        $turnover = (float) $row->turnover;
        $profit = (float) $row->net_profit + $settlementFees;

        return [
            'turnover' => round($turnover, 2),
            'revenue' => round((float) $row->revenue + $settlementFees, 2),
            'settlement_fees' => round($settlementFees, 2),
            'cost' => round((float) $row->cost, 2),
            'net_profit' => round($profit, 2),
            'margin' => $turnover > 0 ? round($profit / $turnover * 100, 2) : null,
            'reports' => (int) $row->reports,
            'sales' => (int) $row->sales,
        ];
    }

    /**
     * Charges taken per settlement, in the base currency, for settlements
     * created in the period (cancelled ones excluded).
     */
    private function settlementFees(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return abs((float) SettlementLine::query()
            ->where('type', SettlementLineType::Fee)
            ->where('currency', config('sterling.base_currency'))
            ->whereHas('settlement', fn (Builder $q) => $q
                ->whereIn('status', SettlementStatus::active())
                ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()]))
            ->sum('amount'));
    }

    /**
     * One point per report date.
     *
     * @return list<array{date: string, turnover: float, net_profit: float}>
     */
    public function daily(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->reports($from, $to)->toBase()
            ->selectRaw('report_date, coalesce(sum(turnover_base), 0) as turnover, coalesce(sum(net_profit_base), 0) as net_profit')
            ->groupBy('report_date')
            ->get()
            ->keyBy(fn ($r) => CarbonImmutable::parse($r->report_date)->toDateString());

        $points = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $key = $day->toDateString();
            $points[] = [
                'date' => $key,
                'turnover' => round((float) ($rows[$key]->turnover ?? 0), 2),
                'net_profit' => round((float) ($rows[$key]->net_profit ?? 0), 2),
            ];
        }

        return $points;
    }

    /**
     * @return list<array{currency: string, turnover: float, net_profit: float, turnover_base: float, net_profit_base: float, reports: int}>
     */
    public function byCurrency(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->reports($from, $to)->toBase()
            ->selectRaw('currency, sum(turnover) as turnover, sum(net_profit) as net_profit, sum(turnover_base) as turnover_base, sum(net_profit_base) as net_profit_base, count(*) as reports')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn ($r) => [
                'currency' => $r->currency,
                'turnover' => round((float) $r->turnover, 2),
                'net_profit' => round((float) $r->net_profit, 2),
                'turnover_base' => round((float) $r->turnover_base, 2),
                'net_profit_base' => round((float) $r->net_profit_base, 2),
                'reports' => (int) $r->reports,
            ])
            ->all();
    }

    /**
     * Margin per provider pair (acquirer ↔ gateway), e.g. Cardaq↔Corefy vs Madfin↔Corefy.
     *
     * @return list<array{pair: string, turnover: float, revenue: float, cost: float, net_profit: float, margin: float|null, mids: int}>
     */
    public function byProviderPair(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->reports($from, $to)->toBase()
            ->join('merchant_mids', 'merchant_mids.id', '=', 'daily_report_tasks.merchant_mid_id')
            ->leftJoin('providers as bank', 'bank.id', '=', 'merchant_mids.bank_provider_id')
            ->leftJoin('providers as gate', 'gate.id', '=', 'merchant_mids.gate_provider_id')
            ->selectRaw('bank.name as bank_name, gate.name as gate_name, sum(daily_report_tasks.turnover_base) as turnover, '
                .'sum('.self::REVENUE.') as revenue, sum('.self::COST.') as cost, sum(daily_report_tasks.net_profit_base) as net_profit, '
                .'count(distinct merchant_mids.id) as mids')
            ->groupBy('bank.name', 'gate.name')
            ->orderByDesc('net_profit')
            ->get()
            ->map(fn ($r) => [
                'pair' => ($r->bank_name ?? 'No acquirer').($r->gate_name ? ' ↔ '.$r->gate_name : ' (clearing only)'),
                'turnover' => round((float) $r->turnover, 2),
                'revenue' => round((float) $r->revenue, 2),
                'cost' => round((float) $r->cost, 2),
                'net_profit' => round((float) $r->net_profit, 2),
                'margin' => (float) $r->turnover > 0 ? round((float) $r->net_profit / (float) $r->turnover * 100, 2) : null,
                'mids' => (int) $r->mids,
            ])
            ->all();
    }

    /**
     * @return list<array{public_id: string, name: string, turnover: float, revenue: float, net_profit: float, margin: float|null, reports: int}>
     */
    public function byMerchant(CarbonImmutable $from, CarbonImmutable $to, int $limit = 15): array
    {
        return $this->reports($from, $to)->toBase()
            ->join('merchants', 'merchants.id', '=', 'daily_report_tasks.merchant_id')
            ->selectRaw('merchants.public_id, merchants.name, sum(daily_report_tasks.turnover_base) as turnover, '
                .'sum('.self::REVENUE.') as revenue, sum(daily_report_tasks.net_profit_base) as net_profit, count(*) as reports')
            ->groupBy('merchants.id', 'merchants.public_id', 'merchants.name')
            ->orderByDesc('net_profit')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'public_id' => $r->public_id,
                'name' => $r->name,
                'turnover' => round((float) $r->turnover, 2),
                'revenue' => round((float) $r->revenue, 2),
                'net_profit' => round((float) $r->net_profit, 2),
                'margin' => (float) $r->turnover > 0 ? round((float) $r->net_profit / (float) $r->turnover * 100, 2) : null,
                'reports' => (int) $r->reports,
            ])
            ->all();
    }

    /**
     * What needs a human: report states, money waiting to go out, reserve held.
     *
     * @return array{reports: array<string, int>, settlements: list<array{status: string, count: int, total: float}>, reserves: list<array{currency: string, balance: float}>}
     */
    public function operations(): array
    {
        $reports = DailyReportTask::query()->toBase()
            ->whereIn('status', [ReportStatus::Pending->value, ReportStatus::Partial->value, ReportStatus::Blocked->value, ReportStatus::Failed->value])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();

        $settlements = Settlement::query()->toBase()
            ->whereIn('status', [SettlementStatus::Draft->value, SettlementStatus::Approved->value])
            ->selectRaw('status, count(*) as total, coalesce(sum(total_payout), 0) as amount')
            ->groupBy('status')
            ->get()
            ->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->total, 'total' => round((float) $r->amount, 2)])
            ->all();

        $reserves = ReserveLedgerEntry::query()->toBase()
            ->selectRaw('currency, sum(amount) as balance')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn ($r) => ['currency' => $r->currency, 'balance' => round((float) $r->balance, 2)])
            ->filter(fn ($r) => $r['balance'] != 0)
            ->values()
            ->all();

        return ['reports' => $reports, 'settlements' => $settlements, 'reserves' => $reserves];
    }

    /**
     * Card and payment-flow analytics from operations of the period.
     *
     * @return array<string, mixed>
     */
    public function breakdowns(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ops = fn () => MerchantOperation::query()->toBase()
            ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()]);
        $sales = fn () => $ops()->where('role', ProviderType::Bank->value)->where('operation_type', OperationType::Sale->value);

        $group = fn ($query, string $expr, string $alias, int $limit = 12) => $query
            ->selectRaw("{$expr} as {$alias}, currency, count(*) as operations, sum(amount) as amount")
            ->groupByRaw("{$expr}, currency")
            ->orderByDesc('operations')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [$alias => $r->{$alias} ?? 'unknown', 'currency' => $r->currency, 'operations' => (int) $r->operations, 'amount' => round((float) $r->amount, 2)])
            ->all();

        // Approval rate on gateways: successful vs declined attempts per acquirer↔gateway pair.
        $conversion = $ops()
            ->where('merchant_operations.role', ProviderType::Gate->value)
            ->join('merchant_mids', 'merchant_mids.id', '=', 'merchant_operations.merchant_mid_id')
            ->leftJoin('providers as bank', 'bank.id', '=', 'merchant_mids.bank_provider_id')
            ->leftJoin('providers as gate', 'gate.id', '=', 'merchant_mids.gate_provider_id')
            ->selectRaw('bank.name as bank_name, gate.name as gate_name, '
                ."sum(case when merchant_operations.operation_type = 'sale' then 1 else 0 end) as approved, "
                ."sum(case when merchant_operations.operation_type = 'decline' then 1 else 0 end) as declined")
            ->groupBy('bank.name', 'gate.name')
            ->get()
            ->map(function ($r) {
                $total = (int) $r->approved + (int) $r->declined;

                return [
                    'pair' => ($r->bank_name ?? '—').' ↔ '.($r->gate_name ?? '—'),
                    'approved' => (int) $r->approved,
                    'declined' => (int) $r->declined,
                    'rate' => $total > 0 ? round((int) $r->approved / $total * 100, 1) : null,
                ];
            })
            ->all();

        return [
            'schemes' => $group($sales(), "coalesce(ips, 'unknown')", 'scheme'),
            'regions' => $group($sales(), "coalesce(region, 'unknown')", 'region'),
            'countries' => $group($sales(), "coalesce(issuer_country, '??')", 'country'),
            'issuers' => $group($sales()->whereNotNull('issuer_name'), 'issuer_name', 'issuer'),
            'decline_reasons' => $group(
                $ops()->where('role', ProviderType::Gate->value)->where('operation_type', OperationType::Decline->value),
                "coalesce(resolution, processing_code, 'unknown')",
                'reason',
            ),
            'conversion' => $conversion,
        ];
    }

    /**
     * Same length period right before [from, to].
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function previous(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = (int) $from->diffInDays($to) + 1;

        return [$from->subDays($days), $from->subDay()];
    }
}

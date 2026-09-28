<?php

namespace App\Profit;

use App\Enums\ProfitShareBase;
use App\Enums\ReportStatus;
use App\Enums\StatementStatus;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use App\Models\MonthlyStatement;
use App\Models\ProfitShareRule;
use App\Models\User;
use App\Services\AuditLogger;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly partner shares from completed daily reports (base currency).
 *
 * A rule for a specific merchant beats the partner's default rule; the rule
 * is picked per report day, so a rule starting mid-month counts only from
 * that day. A closed statement is frozen and never recalculated.
 */
class ProfitShareCalculator
{
    public function calculate(string $month): MonthlyStatement
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $end = $start->endOfMonth()->startOfDay();

        $statement = MonthlyStatement::query()->firstOrCreate(
            ['month' => $start->format('Y-m')],
            ['status' => StatementStatus::Draft, 'base_currency' => config('sterling.base_currency')],
        );
        if ($statement->status === StatementStatus::Closed) {
            return $statement;
        }

        $days = DailyReportTask::query()->toBase()
            ->where('status', ReportStatus::Completed)
            ->whereBetween('report_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('merchant_id, report_date, coalesce(sum(turnover_base), 0) as turnover, coalesce(sum(net_profit_base), 0) as net_profit')
            ->groupBy('merchant_id', 'report_date')
            ->get();

        $rules = ProfitShareRule::query()
            ->with('partner')
            ->whereHas('partner', fn ($q) => $q->where('is_active', true))
            ->get()
            ->filter(fn (ProfitShareRule $rule) => $rule->activeIn($start));

        $merchantNames = Merchant::query()->whereIn('id', $days->pluck('merchant_id')->unique())->pluck('name', 'id');

        /** @var array<string, array{rule: ProfitShareRule, merchant_id: int, base: BigDecimal}> $acc */
        $acc = [];
        $totals = ['turnover' => BigDecimal::zero(), 'net_profit' => BigDecimal::zero()];
        $perMerchant = [];

        foreach ($days as $day) {
            $date = CarbonImmutable::parse($day->report_date)->startOfDay();
            $amounts = [
                ProfitShareBase::NetProfit->value => BigDecimal::of((string) $day->net_profit),
                ProfitShareBase::Turnover->value => BigDecimal::of((string) $day->turnover),
            ];
            $totals['turnover'] = $totals['turnover']->plus($amounts['turnover']);
            $totals['net_profit'] = $totals['net_profit']->plus($amounts['net_profit']);
            $perMerchant[$day->merchant_id] ??= ['turnover' => BigDecimal::zero(), 'net_profit' => BigDecimal::zero()];
            $perMerchant[$day->merchant_id]['turnover'] = $perMerchant[$day->merchant_id]['turnover']->plus($amounts['turnover']);
            $perMerchant[$day->merchant_id]['net_profit'] = $perMerchant[$day->merchant_id]['net_profit']->plus($amounts['net_profit']);

            foreach ($rules->groupBy('profit_partner_id') as $partnerRules) {
                $rule = $this->ruleFor($partnerRules, (int) $day->merchant_id, $date);
                if ($rule === null) {
                    continue;
                }
                $key = $rule->id.'|'.$day->merchant_id;
                $acc[$key] ??= ['rule' => $rule, 'merchant_id' => (int) $day->merchant_id, 'base' => BigDecimal::zero()];
                $acc[$key]['base'] = $acc[$key]['base']->plus($amounts[$rule->base->value]);
            }
        }

        return DB::transaction(function () use ($statement, $acc, $totals, $perMerchant, $merchantNames) {
            $statement->lines()->delete();
            $shares = BigDecimal::zero();

            foreach ($acc as $entry) {
                $rule = $entry['rule'];
                $base = $entry['base']->toScale(2, RoundingMode::HalfUp);
                $share = $entry['base']->multipliedBy($rule->percent)->dividedBy(100, 2, RoundingMode::HalfUp);
                $shares = $shares->plus($share);

                $statement->lines()->create([
                    'profit_partner_id' => $rule->profit_partner_id,
                    'partner_name' => $rule->partner->name,
                    'merchant_id' => $entry['merchant_id'],
                    'merchant_name' => $merchantNames[$entry['merchant_id']] ?? '#'.$entry['merchant_id'],
                    'profit_share_rule_id' => $rule->id,
                    'base' => $rule->base,
                    'base_amount' => (string) $base,
                    'percent' => $rule->percent,
                    'share' => (string) $share,
                ]);
            }

            $netProfit = $totals['net_profit']->toScale(2, RoundingMode::HalfUp);
            $statement->update([
                'turnover' => (string) $totals['turnover']->toScale(2, RoundingMode::HalfUp),
                'net_profit' => (string) $netProfit,
                'shares_total' => (string) $shares,
                'company_remainder' => (string) $netProfit->minus($shares),
                'merchants' => collect($perMerchant)->map(fn ($v, $id) => [
                    'merchant_id' => $id,
                    'name' => $merchantNames[$id] ?? '#'.$id,
                    'turnover' => (string) $v['turnover']->toScale(2, RoundingMode::HalfUp),
                    'net_profit' => (string) $v['net_profit']->toScale(2, RoundingMode::HalfUp),
                ])->sortByDesc(fn ($m) => (float) $m['net_profit'])->values()->all(),
                'calculated_at' => now(),
            ]);

            return $statement->refresh();
        });
    }

    public function close(MonthlyStatement $statement, User $by): MonthlyStatement
    {
        if ($statement->status === StatementStatus::Closed) {
            return $statement;
        }
        if (CarbonImmutable::createFromFormat('!Y-m', $statement->month)->endOfMonth()->isFuture()) {
            throw ValidationException::withMessages(['statement' => 'A month can be closed only after it ends.']);
        }

        $statement = $this->calculate($statement->month);
        $statement->update(['status' => StatementStatus::Closed, 'closed_by' => $by->id, 'closed_at' => now()]);
        AuditLogger::log('profit_statement.closed', $statement, ['month' => $statement->month, 'remainder' => $statement->company_remainder]);

        return $statement;
    }

    public function reopen(MonthlyStatement $statement, User $by): MonthlyStatement
    {
        $statement->update(['status' => StatementStatus::Draft, 'closed_by' => null, 'closed_at' => null]);
        AuditLogger::log('profit_statement.reopened', $statement, ['month' => $statement->month]);

        return $this->calculate($statement->month);
    }

    /**
     * @param  Collection<int, ProfitShareRule>  $rules  one partner's rules
     */
    private function ruleFor(Collection $rules, int $merchantId, CarbonImmutable $day): ?ProfitShareRule
    {
        $inForce = $rules->filter(fn (ProfitShareRule $r) => $r->valid_from->lessThanOrEqualTo($day)
            && ($r->valid_to === null || $r->valid_to->greaterThanOrEqualTo($day)));

        return $inForce->where('merchant_id', $merchantId)->sortByDesc('valid_from')->first()
            ?? $inForce->whereNull('merchant_id')->sortByDesc('valid_from')->first();
    }
}

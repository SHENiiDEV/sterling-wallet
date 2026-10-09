<?php

namespace App\Reports\Generation;

use App\Enums\MerchantStatus;
use App\Enums\MidStatus;
use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Enums\ReserveEntryType;
use App\Mail\DailyReportMail;
use App\Models\DailyReportTask;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Models\ReserveLedgerEntry;
use App\Reports\Reconciliation\ReconciliationService;
use App\Settlements\SettlementService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The only place that calculates a daily report and our profit on it.
 * Everything is computed in the MID currency with arbitrary-precision
 * decimals and rounded to cents once per total.
 */
class DailyReportGenerator
{
    private const SCALE = 2;

    public function __construct(
        private ReconciliationService $reconciler,
        private FxRateResolver $fx,
        private ReportDocuments $documents,
    ) {}

    public function generate(DailyReportTask $task, bool $sendEmail = true): DailyReportTask
    {
        $task->load('merchantMid.merchant.cryptoProvider', 'merchantMid.bankProvider', 'merchantMid.gateProvider');

        // Money was approved or paid on it: late data must not change it silently.
        if ($task->status === ReportStatus::Completed && $task->lockReason() !== null) {
            return $task;
        }

        if ($task->missingProviderIds() !== []) {
            $task->update(['status' => ReportStatus::Partial]);

            return $task;
        }

        try {
            $this->guard($task);
            $this->calculate($task);
        } catch (ReportBlocked $blocked) {
            $task->update(['status' => ReportStatus::Blocked, 'error_log' => $blocked->getMessage()]);

            return $task;
        }

        $this->documents->write($task);
        app(SettlementService::class)->syncReport($task->refresh());

        $email = $task->merchant->invoice_email;
        if ($sendEmail && $email && ! $task->is_email_sent) {
            Mail::to($email)->queue(new DailyReportMail($task));
            $task->update(['is_email_sent' => true, 'email_sent_at' => now()]);
        }

        return $task;
    }

    private function guard(DailyReportTask $task): void
    {
        $mid = $task->merchantMid;
        $merchant = $mid->merchant;

        if ($mid->status === MidStatus::Review) {
            throw new ReportBlocked("MID {$mid->mid} is waiting for review.");
        }
        if ($merchant->status === MerchantStatus::Review) {
            throw new ReportBlocked("Merchant {$merchant->name} is waiting for review.");
        }
        if ($mid->bank_provider_id === null) {
            throw new ReportBlocked("MID {$mid->mid} has no acquirer.");
        }
        if (($missing = $merchant->missingTariffFields()) !== []) {
            throw new ReportBlocked('Merchant tariff is incomplete: '.implode(', ', $missing).'.');
        }
    }

    private function calculate(DailyReportTask $task): void
    {
        $mid = $task->merchantMid;
        $merchant = $mid->merchant;
        $currency = $mid->currency->value;

        $this->reconciler->reconcile($mid, $task->period_from, $task->period_to);

        $operations = MerchantOperation::query()
            ->where('merchant_mid_id', $mid->id)
            ->whereBetween('report_date', [$task->period_from->toDateString(), $task->period_to->toDateString()])
            ->get();

        $bankOps = $operations->where('provider_id', $mid->bank_provider_id)->where('role', ProviderType::Bank);
        $gateOps = $mid->gate_provider_id ? $operations->where('provider_id', $mid->gate_provider_id)->where('role', ProviderType::Gate) : collect();

        $sales = $bankOps->where('operation_type', OperationType::Sale);
        $refunds = $bankOps->where('operation_type', OperationType::Refund);
        $chargebacks = $bankOps->where('operation_type', OperationType::Chargeback);
        // Per-transaction fees follow the gateway when there is one: every
        // approved or declined attempt passes through it. Declines never
        // reach clearing at all. Refunds and chargebacks are acquirer events.
        $txSource = $mid->gate_provider_id ? $gateOps : $bankOps;
        $approved = $txSource->where('operation_type', OperationType::Sale);
        $declines = $txSource->where('operation_type', OperationType::Decline);

        $turnover = $this->sum($sales);
        $refundsAmount = $this->sum($refunds);
        $chargebacksAmount = $this->sum($chargebacks);

        // What the merchant pays us.
        $fee = Tariff::merchant($merchant);
        $merchantPercentFee = $this->percentOf($sales, $fee);
        $fixedLines = [];
        $merchantFixedFee = BigDecimal::zero();
        foreach (['success' => $approved, 'decline' => $declines, 'refund' => $refunds, 'chargeback' => $chargebacks] as $name => $ops) {
            $unit = $fee->fixed($name);
            $total = $unit->multipliedBy($ops->count());
            $merchantFixedFee = $merchantFixedFee->plus($total);
            $fixedLines[$name] = ['count' => $ops->count(), 'unit' => (string) $unit, 'total' => (string) $this->round($total)];
        }

        // Settlement FX markup: a MID outside the settlement currency is
        // converted by the acquirer, on what it settles (sales less
        // refunds and chargebacks). We charge the merchant and the
        // acquirer charges us, both on the same amount.
        $settled = BigDecimal::max(BigDecimal::zero(), $turnover->minus($refundsAmount)->minus($chargebacksAmount));
        $fxApplies = $currency !== config('sterling.settlement_currency');
        $fxFee = $this->round($fxApplies ? $this->percentAmount($settled, $fee->percent('settlement_fx')) : BigDecimal::zero());
        $fxCost = $fxApplies && $mid->bankProvider
            ? $this->round($this->percentAmount($settled, Tariff::provider($mid->bankProvider)->percent('settlement_fx')))
            : $this->round(BigDecimal::zero());

        $walletSales = $sales->filter(fn (MerchantOperation $op) => $op->wallet !== null);
        $walletFee = $this->round($walletSales->reduce(
            fn (BigDecimal $c, MerchantOperation $op) => $c->plus($this->percentAmount(BigDecimal::of($op->amount)->abs(), $fee->percent('wallet'))),
            BigDecimal::zero(),
        ));

        $merchantFee = $this->round($merchantPercentFee->plus($merchantFixedFee)->plus($fxFee));

        // What the providers charge us, each on its own operations.
        $bankCost = $this->providerCost($mid->bankProvider, $bankOps);
        $gateCost = $mid->gateProvider ? $this->providerCost($mid->gateProvider, $gateOps) : BigDecimal::zero();

        // Rolling reserve, capped by what's left under the MID limit.
        $reserveBase = $turnover->minus($refundsAmount)->minus($chargebacksAmount)->minus($merchantFee);
        $reserve = $this->round(BigDecimal::max(BigDecimal::zero(), $reserveBase)
            ->multipliedBy($merchant->rolling_reserve_percent ?? 0)->dividedBy(100, 8, RoundingMode::HalfUp));
        $reserve = $this->capReserve($task, $reserve);

        $netVolume = $reserveBase->minus($reserve);
        $convertible = BigDecimal::max(BigDecimal::zero(), $netVolume);
        $conversionFee = $this->round($convertible->multipliedBy($fee->percent('fiat_to_crypto'))->dividedBy(100, 8, RoundingMode::HalfUp));
        $cryptoCost = $merchant->cryptoProvider
            ? $this->round($convertible->multipliedBy(Tariff::provider($merchant->cryptoProvider)->percent('crypto'))->dividedBy(100, 8, RoundingMode::HalfUp))
            : BigDecimal::zero();

        $netPayout = $netVolume->minus($conversionFee);
        $providerCost = $bankCost->plus($gateCost)->plus($cryptoCost)->plus($fxCost);
        $netProfit = $merchantFee->plus($conversionFee)->minus($providerCost);

        $baseCurrency = config('sterling.base_currency');
        $rate = $this->fx->rate($currency, $baseCurrency, $task->report_date);
        if ($rate === null) {
            throw new ReportBlocked("No {$currency}→{$baseCurrency} FX rate on or before {$task->report_date->toDateString()}.");
        }

        DB::transaction(function () use ($task, $merchant, $fee, $fixedLines, $fxFee, $fxCost, $fxApplies, $walletSales, $walletFee, $reserve, $currency, $baseCurrency, $rate, $sales, $refunds, $chargebacks, $declines, $bankOps, $gateOps, $turnover, $refundsAmount, $chargebacksAmount, $merchantFee, $merchantPercentFee, $merchantFixedFee, $bankCost, $gateCost, $cryptoCost, $providerCost, $netVolume, $conversionFee, $netPayout, $netProfit) {
            $this->bookReserve($task, $reserve, $merchant->rolling_reserve_days);

            $task->update([
                'status' => ReportStatus::Completed,
                'error_log' => null,
                'currency' => $currency,
                'sales_count' => $sales->count(),
                'turnover' => (string) $turnover,
                'refunds_amount' => (string) $refundsAmount,
                'chargebacks_amount' => (string) $chargebacksAmount,
                'total_merchant_fee' => (string) $merchantFee,
                'total_provider_cost' => (string) $providerCost,
                'reserve_amount' => (string) $reserve,
                'net_volume' => (string) $netVolume,
                'conversion_fee' => (string) $conversionFee,
                'net_payout' => (string) $netPayout,
                'net_profit' => (string) $netProfit,
                'base_currency' => $baseCurrency,
                'fx_rate' => (string) $rate->toScale(8, RoundingMode::HalfUp),
                'turnover_base' => (string) $turnover->multipliedBy($rate)->toScale(4, RoundingMode::HalfUp),
                'net_profit_base' => (string) $netProfit->multipliedBy($rate)->toScale(4, RoundingMode::HalfUp),
                'summary_data' => [
                    'counts' => [
                        'sales' => $sales->count(),
                        'refunds' => $refunds->count(),
                        'chargebacks' => $chargebacks->count(),
                        'declines' => $declines->count(),
                        'bank_operations' => $bankOps->count(),
                        'gate_operations' => $gateOps->count(),
                        'unmatched_bank' => $bankOps->where('operation_type', '!=', OperationType::Decline)->whereNull('matched_operation_id')->count(),
                        'unmatched_gate' => $gateOps->where('operation_type', '!=', OperationType::Decline)->whereNull('matched_operation_id')->count(),
                    ],
                    'merchant_fee' => [
                        'percent' => (string) $this->round($merchantPercentFee),
                        'fixed' => (string) $this->round($merchantFixedFee),
                        'fixed_lines' => $fixedLines,
                        'fx_markup' => (string) $fxFee,
                        'fx_markup_percent' => $fxApplies ? (string) $fee->percent('settlement_fx') : '0',
                        'wallet' => [
                            'count' => $walletSales->count(),
                            'amount' => (string) $this->sum($walletSales),
                            'percent' => (string) $fee->percent('wallet'),
                            'fee' => (string) $walletFee,
                        ],
                        'conversion_percent' => (string) $fee->percent('fiat_to_crypto'),
                        'reserve_percent' => (string) ($merchant->rolling_reserve_percent ?? 0),
                    ],
                    'provider_cost' => [
                        'bank' => (string) $bankCost,
                        'gate' => (string) $gateCost,
                        'crypto' => (string) $cryptoCost,
                    ],
                    'provider_fx_markup' => (string) $fxCost,
                    'by_scheme' => $this->breakdown($sales, $fee),
                ],
                'generated_at' => now(),
            ]);
        });
    }

    /**
     * Provider cost on its own operations: percent on sales plus fixed fees.
     *
     * @param  Collection<int, MerchantOperation>  $ops
     */
    private function providerCost(?Provider $provider, Collection $ops): BigDecimal
    {
        if ($provider === null) {
            return BigDecimal::zero();
        }

        $cost = Tariff::provider($provider);
        $sales = $ops->where('operation_type', OperationType::Sale);

        return $this->round($this->percentOf($sales, $cost)
            ->plus($cost->fixed('success')->multipliedBy($sales->count()))
            ->plus($cost->fixed('refund')->multipliedBy($ops->where('operation_type', OperationType::Refund)->count()))
            ->plus($cost->fixed('chargeback')->multipliedBy($ops->where('operation_type', OperationType::Chargeback)->count()))
            ->plus($cost->fixed('decline')->multipliedBy($ops->where('operation_type', OperationType::Decline)->count())));
    }

    /**
     * @param  Collection<int, MerchantOperation>  $sales
     */
    private function percentOf(Collection $sales, Tariff $tariff): BigDecimal
    {
        return $sales->reduce(
            fn (BigDecimal $carry, MerchantOperation $op) => $carry->plus(
                BigDecimal::of($op->amount)->abs()->multipliedBy($tariff->percentFor($op))->dividedBy(100, 8, RoundingMode::HalfUp),
            ),
            BigDecimal::zero(),
        );
    }

    /**
     * @param  Collection<int, MerchantOperation>  $ops
     */
    private function sum(Collection $ops): BigDecimal
    {
        return $this->round($ops->reduce(fn (BigDecimal $c, MerchantOperation $op) => $c->plus(BigDecimal::of($op->amount)->abs()), BigDecimal::zero()));
    }

    private function percentAmount(BigDecimal $amount, BigDecimal $percent): BigDecimal
    {
        return $amount->multipliedBy($percent)->dividedBy(100, 8, RoundingMode::HalfUp);
    }

    private function round(BigDecimal $value): BigDecimal
    {
        return $value->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    /**
     * Reserve may not push the MID balance past its limit (0 = no limit).
     * This report's own earlier holds don't count: they get reversed.
     */
    private function capReserve(DailyReportTask $task, BigDecimal $reserve): BigDecimal
    {
        $others = fn () => ReserveLedgerEntry::query()
            ->where(fn ($q) => $q->whereNull('daily_report_task_id')->orWhere('daily_report_task_id', '!=', $task->id));

        $limit = BigDecimal::of($task->merchantMid->rolling_reserve_limit ?? 0);
        if ($limit->isPositive()) {
            $balance = BigDecimal::of((string) $others()->where('merchant_mid_id', $task->merchant_mid_id)->sum('amount'));
            $reserve = BigDecimal::min($reserve, BigDecimal::max(BigDecimal::zero(), $limit->minus($balance)));
        }

        // Merchant-wide cap (Appendix 1: maximum reserve balance): once the
        // balance reaches it nothing more is held and the rest is paid out.
        $cap = BigDecimal::of($task->merchantMid->merchant->rolling_reserve_cap ?? 0);
        if ($cap->isPositive()) {
            $balance = BigDecimal::of((string) $others()
                ->where('merchant_id', $task->merchant_id)
                ->where('currency', $task->merchantMid->currency->value)
                ->sum('amount'));
            $reserve = BigDecimal::min($reserve, BigDecimal::max(BigDecimal::zero(), $cap->minus($balance)));
        }

        return $reserve->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    /**
     * Reverse whatever this report held before, then hold the new amount.
     */
    private function bookReserve(DailyReportTask $task, BigDecimal $reserve, ?int $days): void
    {
        $entries = ReserveLedgerEntry::query()
            ->where('daily_report_task_id', $task->id)
            ->whereIn('type', [ReserveEntryType::Hold, ReserveEntryType::Adjustment]);
        $previous = BigDecimal::of((string) $entries->sum('amount'));

        $base = [
            'merchant_id' => $task->merchant_id,
            'merchant_mid_id' => $task->merchant_mid_id,
            'daily_report_task_id' => $task->id,
            'currency' => $task->merchantMid->currency->value,
        ];

        if (! $previous->isZero()) {
            ReserveLedgerEntry::query()->create([
                ...$base,
                'type' => ReserveEntryType::Adjustment,
                'amount' => (string) $previous->negated(),
                'note' => 'Reversal: report regenerated',
            ]);
        }

        if ($reserve->isPositive()) {
            ReserveLedgerEntry::query()->create([
                ...$base,
                'type' => ReserveEntryType::Hold,
                'amount' => (string) $reserve,
                'release_on' => $task->report_date->addDays($days ?? 180),
                'note' => 'Rolling reserve '.$task->report_date->toDateString(),
            ]);
        }
    }

    /**
     * Sales and the merchant percent fee per scheme × region, e.g.
     * `mastercard_eu`. The four Visa / Mastercard × EU / non-EU groups are
     * always present; anything else (unknown scheme or region) is added.
     *
     * @param  Collection<int, MerchantOperation>  $sales
     * @return array<string, array{count: int, amount: string, rate: string, fee: string}>
     */
    private function breakdown(Collection $sales, Tariff $fee): array
    {
        $groups = $sales->groupBy(fn (MerchantOperation $op) => ($op->ips ?? 'other').'_'.($op->region ?? 'unknown'));

        $result = [];
        foreach (array_unique(['mastercard_eu', 'mastercard_non_eu', 'visa_eu', 'visa_non_eu', ...$groups->keys()->all()]) as $key) {
            $group = $groups->get($key, collect());
            [$scheme, $region] = explode('_', $key, 2);
            $probe = (new MerchantOperation)->forceFill([
                'ips' => $scheme === 'other' ? null : $scheme,
                'region' => $region === 'unknown' ? null : $region,
            ]);

            $result[$key] = [
                'count' => $group->count(),
                'amount' => (string) $this->sum($group),
                'rate' => (string) $fee->percentFor($probe),
                'fee' => (string) $this->round($this->percentOf($group, $fee)),
            ];
        }

        return $result;
    }
}

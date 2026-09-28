<?php

namespace App\Reports\Generation;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Enums\ReserveEntryType;
use App\Models\DailyReportTask;
use App\Models\MerchantOperation;
use App\Models\ReserveLedgerEntry;
use App\Support\PdfRenderer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The merchant-facing daily report PDF: the payout calculation step by
 * step, fees per card scheme and the operations behind it. Uses only the
 * numbers the generator stored — our provider cost and profit stay out.
 */
class DailyStatement
{
    /** Operations listed in the PDF; the CSV always has all of them. */
    public const MAX_OPERATIONS = 400;

    private const SCHEMES = ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'other' => 'Other'];

    private const REGIONS = ['eu' => 'EU / EEA', 'non_eu' => 'Non-EU', 'unknown' => 'Region n/a'];

    public function render(DailyReportTask $task): string
    {
        return PdfRenderer::render('reports.daily', $this->data($task));
    }

    /**
     * @return array<string, mixed>
     */
    public function data(DailyReportTask $task): array
    {
        $task->loadMissing('merchant.company', 'merchantMid.bankProvider');
        $merchant = $task->merchant;
        $currency = $task->currency;
        $tariff = Tariff::merchant($merchant);
        $summary = $task->summary_data ?? [];
        $counts = $summary['counts'] ?? [];
        $count = fn (string $key) => (int) ($counts[$key] ?? 0);

        $turnover = $this->dec($task->turnover);
        $refunds = $this->dec($task->refunds_amount);
        $chargebacks = $this->dec($task->chargebacks_amount);
        $fee = $this->dec($task->total_merchant_fee);
        $percentFee = $this->dec($summary['merchant_fee']['percent'] ?? $task->total_merchant_fee);
        $fixedFee = $this->dec($summary['merchant_fee']['fixed'] ?? 0);
        $reserve = $this->dec($task->reserve_amount);
        $netVolume = $this->dec($task->net_volume);
        $conversion = $this->dec($task->conversion_fee);
        $payout = $this->dec($task->net_payout);
        $afterFees = $turnover->minus($refunds)->minus($chargebacks)->minus($fee);

        $fixed = [];
        foreach (['success' => ['sales', 'Approved sale'], 'refund' => ['refunds', 'Refund'], 'chargeback' => ['chargebacks', 'Chargeback'], 'decline' => ['declines', 'Declined attempt']] as $name => [$key, $label]) {
            $unit = $tariff->fixed($name);
            if ($unit->isPositive() && $count($key) > 0) {
                $fixed[] = sprintf('%d × %s %s', $count($key), PdfRenderer::money($unit, $currency), strtolower($label));
            }
        }

        $hold = ReserveLedgerEntry::query()
            ->where('daily_report_task_id', $task->id)
            ->where('type', ReserveEntryType::Hold)
            ->latest('id')
            ->first();
        $reservePercent = $merchant->rolling_reserve_percent ?? 0;
        $expectedReserve = BigDecimal::max(BigDecimal::zero(), $afterFees)->multipliedBy($reservePercent)->dividedBy(100, 2, RoundingMode::HalfUp);
        $reserveDetail = PdfRenderer::percent($reservePercent).' of net after fees'
            .($hold?->release_on ? ' · released '.$hold->release_on->toDateString() : '')
            .($reserve->isLessThan($expectedReserve) ? ' · capped by the MID reserve limit' : '');

        $steps = [
            ['kind' => 'plus', 'label' => 'Gross sales', 'detail' => $count('sales').' approved '.($count('sales') === 1 ? 'sale' : 'sales'), 'amount' => $turnover],
            ['kind' => 'minus', 'label' => 'Refunds', 'detail' => $count('refunds').' refunded', 'amount' => $refunds->negated()],
            ['kind' => 'minus', 'label' => 'Chargebacks', 'detail' => $count('chargebacks').' disputed', 'amount' => $chargebacks->negated()],
            ['kind' => 'minus', 'label' => 'Processing fee', 'detail' => 'Percentage by card scheme and region — see breakdown', 'amount' => $percentFee->negated()],
            ['kind' => 'minus', 'label' => 'Transaction fees', 'detail' => $fixed === [] ? 'None' : implode(' · ', $fixed), 'amount' => $fixedFee->negated()],
            ['kind' => 'subtotal', 'label' => 'Net after fees', 'detail' => null, 'amount' => $afterFees],
            ['kind' => 'minus', 'label' => 'Rolling reserve', 'detail' => $reserveDetail, 'amount' => $reserve->negated()],
            ['kind' => 'subtotal', 'label' => 'Net volume', 'detail' => null, 'amount' => $netVolume],
            ['kind' => 'minus', 'label' => 'Conversion fee', 'detail' => PdfRenderer::percent($tariff->percent('fiat_to_crypto')).' fiat → crypto', 'amount' => $conversion->negated()],
            ['kind' => 'total', 'label' => 'Net payout', 'detail' => 'Added to your next settlement', 'amount' => $payout],
        ];

        $reserveBalance = $this->dec(ReserveLedgerEntry::query()
            ->where('merchant_mid_id', $task->merchant_mid_id)
            ->when($task->generated_at, fn ($q) => $q->where('created_at', '<=', $task->generated_at))
            ->sum('amount'));

        [$operations, $operationsTotal] = $this->operations($task);

        return [
            'task' => $task,
            'merchant' => $merchant,
            'currency' => $currency,
            'acquirer' => $task->merchantMid->bankProvider?->name,
            'steps' => $steps,
            'schemes' => $this->schemes($summary['by_scheme'] ?? [], $tariff, $currency),
            'percentFee' => $percentFee,
            'counts' => [
                'sales' => $count('sales'),
                'refunds' => $count('refunds'),
                'chargebacks' => $count('chargebacks'),
                'declines' => $count('declines'),
            ],
            'tiles' => [
                ['Gross sales', PdfRenderer::money($turnover, $currency), $count('sales').' transactions', false],
                ['Fees', PdfRenderer::money($fee->plus($conversion), $currency), 'Processing + conversion', false],
                ['Reserve held', PdfRenderer::money($reserve, $currency), 'MID balance '.PdfRenderer::money($reserveBalance, $currency), false],
                ['Net payout', PdfRenderer::money($payout, $currency), $task->report_date->toDateString(), true],
            ],
            'approvalRate' => $count('sales') + $count('declines') > 0
                ? round($count('sales') / ($count('sales') + $count('declines')) * 100, 1)
                : null,
            'averageTicket' => $count('sales') > 0 ? $turnover->dividedBy($count('sales'), 2, RoundingMode::HalfUp) : null,
            'operations' => $operations,
            'operationsTotal' => $operationsTotal,
        ];
    }

    /**
     * @param  array<string, array{count: int, amount: string}>  $groups
     * @return list<array{label: string, count: int, amount: BigDecimal, rate: BigDecimal, fee: BigDecimal}>
     */
    private function schemes(array $groups, Tariff $tariff, string $currency): array
    {
        $rows = [];
        foreach ($groups as $key => $group) {
            [$scheme, $region] = array_pad(explode('_', $key, 2), 2, 'unknown');
            $probe = (new MerchantOperation)->forceFill([
                'ips' => $scheme === 'other' ? null : $scheme,
                'region' => $region === 'unknown' ? null : $region,
            ]);
            $rate = $tariff->percentFor($probe);
            $amount = $this->dec($group['amount']);

            $rows[] = [
                'label' => (self::SCHEMES[$scheme] ?? ucfirst($scheme)).' · '.(self::REGIONS[$region] ?? $region),
                'count' => (int) $group['count'],
                'amount' => $amount,
                'rate' => $rate,
                'fee' => $amount->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HalfUp),
            ];
        }

        return $rows;
    }

    /**
     * Acquirer-side sales, refunds and chargebacks of the period, oldest first.
     *
     * @return array{0: list<MerchantOperation>, 1: int}
     */
    private function operations(DailyReportTask $task): array
    {
        $query = MerchantOperation::query()
            ->where('merchant_mid_id', $task->merchant_mid_id)
            ->where('provider_id', $task->merchantMid->bank_provider_id)
            ->where('role', ProviderType::Bank)
            ->whereIn('operation_type', [OperationType::Sale, OperationType::Refund, OperationType::Chargeback])
            ->whereBetween('report_date', [$task->period_from->toDateString(), $task->period_to->toDateString()]);

        $total = (clone $query)->count();
        $rows = $query->orderBy('transaction_at')->orderBy('id')->limit(self::MAX_OPERATIONS)->get()->all();

        return [$rows, $total];
    }

    private function dec(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? 0))->toScale(2, RoundingMode::HalfUp);
    }
}

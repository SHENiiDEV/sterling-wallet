<?php

namespace App\Settlements;

use App\Enums\SettlementLineType;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Support\PdfRenderer;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * The settlement statement: every daily report it pays with its own
 * calculation, reserve releases and adjustments, then the conversion of
 * each currency into the payout currency and the final amount.
 */
class SettlementStatementPdf
{
    public function render(Settlement $settlement): string
    {
        return PdfRenderer::render('settlements.statement', $this->data($settlement));
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Settlement $settlement): array
    {
        $settlement->load([
            'merchant.company', 'wallet', 'approver:id,name', 'settler:id,name', 'creator:id,name',
            'lines.dailyReport.merchantMid:id,mid', 'lines.reserveEntry.merchantMid:id,mid',
        ]);
        $lines = $settlement->lines->sortBy('id')->values();

        $reports = $lines->where('type', SettlementLineType::Report)
            ->sortBy(fn (SettlementLine $l) => [$l->currency, $l->dailyReport?->report_date?->toDateString(), $l->id])
            ->groupBy('currency')
            ->map(fn (Collection $group, string $currency) => $this->reportGroup($group, $currency));

        $releases = $lines->where('type', SettlementLineType::ReserveRelease)->values();
        $adjustments = $lines->whereIn('type', [SettlementLineType::Adjustment, SettlementLineType::Fee])->values();

        // Per currency: what the lines add up to, the rate, and the payout.
        $conversion = $lines->groupBy('currency')->map(fn (Collection $group, string $currency) => [
            'currency' => $currency,
            'reports' => $this->sum($group->where('type', SettlementLineType::Report), 'amount'),
            'releases' => $this->sum($group->where('type', SettlementLineType::ReserveRelease), 'amount'),
            'adjustments' => $this->sum($group->whereIn('type', [SettlementLineType::Adjustment, SettlementLineType::Fee]), 'amount'),
            'amount' => $this->sum($group, 'amount'),
            'rate' => $settlement->rates[$currency] ?? null,
            'payout' => $this->sum($group, 'amount_payout'),
        ])->sortKeys()->values();

        $dates = $lines->map(fn (SettlementLine $l) => $l->dailyReport?->report_date)->filter();
        $turnover = $reports->map(fn (array $g) => PdfRenderer::money($g['totals']['turnover'], $g['currency']))->values()->all();
        $fees = $reports->map(fn (array $g) => PdfRenderer::money($g['totals']['fees'], $g['currency']))->values()->all();

        return [
            'settlement' => $settlement,
            'company' => $settlement->merchant->company->name ?? $settlement->merchant->name,
            'periodFrom' => $dates->min(),
            'periodTo' => $dates->max(),
            'reportGroups' => $reports->values()->all(),
            'releases' => $releases,
            'adjustments' => $adjustments,
            'conversion' => $conversion->all(),
            'explorerUrl' => self::explorerUrl($settlement->wallet?->network, $settlement->tx_hash),
            'tiles' => [
                ['Daily reports', (string) $lines->where('type', SettlementLineType::Report)->count(), $dates->isEmpty() ? '—' : $dates->min()->toDateString().' — '.$dates->max()->toDateString(), false],
                ['Gross sales', $turnover === [] ? '—' : $turnover, 'Before refunds and fees', false],
                ['Fees', $fees === [] ? '—' : $fees, 'Processing + conversion', false],
                ['Total payout', PdfRenderer::money($settlement->total_payout, $settlement->payout_currency), $settlement->wallet ? trim($settlement->wallet->currency.' '.$settlement->wallet->network) : 'Wallet not set', true],
            ],
        ];
    }

    /**
     * Block explorer link for the payout transaction, when the network is known.
     */
    public static function explorerUrl(?string $network, ?string $txHash): ?string
    {
        if (! $txHash) {
            return null;
        }

        $network = strtolower((string) $network);
        $base = match (true) {
            str_contains($network, 'trc') || str_contains($network, 'tron') => 'https://tronscan.org/#/transaction/',
            str_contains($network, 'erc') || str_contains($network, 'eth') => 'https://etherscan.io/tx/',
            str_contains($network, 'bep') || str_contains($network, 'bsc') => 'https://bscscan.com/tx/',
            str_contains($network, 'polygon') || str_contains($network, 'matic') => 'https://polygonscan.com/tx/',
            str_contains($network, 'sol') => 'https://solscan.io/tx/',
            default => null,
        };

        return $base ? $base.rawurlencode($txHash) : null;
    }

    /**
     * @param  Collection<int, SettlementLine>  $lines
     * @return array{currency: string, rows: list<array<string, mixed>>, totals: array<string, BigDecimal|int>}
     */
    private function reportGroup(Collection $lines, string $currency): array
    {
        $rows = [];
        $totals = array_fill_keys(['turnover', 'refunds', 'fees', 'reserve', 'payout'], BigDecimal::zero());
        $totals['sales'] = 0;

        foreach ($lines as $line) {
            $report = $line->dailyReport;
            $row = [
                'date' => $report?->report_date?->toDateString() ?? '—',
                'mid' => $report?->merchantMid?->mid ?? '—',
                'sales' => (int) ($report?->sales_count ?? 0),
                'turnover' => $this->dec($report?->turnover),
                'refunds' => $this->dec($report?->refunds_amount)->plus($this->dec($report?->chargebacks_amount)),
                'fees' => $this->dec($report?->total_merchant_fee)->plus($this->dec($report?->conversion_fee)),
                'reserve' => $this->dec($report?->reserve_amount),
                'payout' => $this->dec($line->amount),
            ];
            $rows[] = $row;

            foreach (['turnover', 'refunds', 'fees', 'reserve', 'payout'] as $key) {
                $totals[$key] = $totals[$key]->plus($row[$key]);
            }
            $totals['sales'] += $row['sales'];
        }

        return ['currency' => $currency, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * @param  Collection<int, SettlementLine>  $lines
     */
    private function sum(Collection $lines, string $field): BigDecimal
    {
        return $lines->reduce(fn (BigDecimal $c, SettlementLine $l) => $c->plus($this->dec($l->{$field})), BigDecimal::zero());
    }

    private function dec(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? 0));
    }
}

<?php

namespace App\Profit;

use App\Enums\StatementStatus;
use App\Models\MonthlyStatement;
use App\Models\MonthlyStatementLine;
use App\Support\PdfRenderer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Profit share PDFs: the full monthly statement (company totals, profit by
 * merchant, every partner) and a partner statement that shows one partner
 * only their own lines.
 */
class ProfitShareStatementPdf
{
    public function render(MonthlyStatement $statement, ?int $partnerId = null): string
    {
        return $partnerId === null
            ? PdfRenderer::render('profit.statement', $this->data($statement))
            : PdfRenderer::render('profit.partner-statement', $this->partnerData($statement, $partnerId));
    }

    /**
     * @return array<string, mixed>
     */
    public function data(MonthlyStatement $statement): array
    {
        $statement->loadMissing('lines', 'closer:id,name');
        $currency = $statement->base_currency;
        $profit = $this->dec($statement->net_profit);
        $lines = $statement->lines;

        $partners = $lines->groupBy(fn (MonthlyStatementLine $l) => $l->profit_partner_id ?? $l->partner_name)
            ->map(fn (Collection $group) => [
                'name' => $group->first()->partner_name,
                'merchants' => $group->pluck('merchant_name')->unique()->count(),
                'share' => $this->sum($group),
                'of_profit' => $this->percentOf($this->sum($group), $profit),
                'lines' => $group->sortBy('merchant_name')->values(),
            ])
            ->sortByDesc(fn (array $p) => (float) (string) $p['share'])
            ->values();

        $sharesByMerchant = $lines->groupBy(fn (MonthlyStatementLine $l) => $l->merchant_id ?? $l->merchant_name)
            ->map(fn (Collection $group) => $this->sum($group));
        $merchants = collect($statement->merchants ?? [])->map(function (array $m) use ($sharesByMerchant) {
            $turnover = $this->dec($m['turnover']);
            $net = $this->dec($m['net_profit']);
            $shares = $sharesByMerchant[$m['merchant_id']] ?? $sharesByMerchant[$m['name']] ?? BigDecimal::zero();

            return [
                'name' => $m['name'],
                'turnover' => $turnover,
                'net_profit' => $net,
                'margin' => $this->percentOf($net, $turnover),
                'shares' => $shares,
                'remainder' => $net->minus($shares),
            ];
        });

        return [
            'statement' => $statement,
            'currency' => $currency,
            'monthLabel' => $this->monthLabel($statement->month),
            'closed' => $statement->status === StatementStatus::Closed,
            'negative' => $this->dec($statement->company_remainder)->isNegative(),
            'partners' => $partners->all(),
            'merchants' => $merchants->all(),
            'tiles' => [
                ['Turnover', PdfRenderer::money($statement->turnover, $currency), $merchants->count().' merchant(s)', false],
                ['Net profit', PdfRenderer::money($statement->net_profit, $currency), 'Margin '.$this->percentOf($profit, $this->dec($statement->turnover)), false],
                ['Partner shares', PdfRenderer::money($statement->shares_total, $currency), $this->percentOf($this->dec($statement->shares_total), $profit).' of net profit', false],
                ['Company remainder', PdfRenderer::money($statement->company_remainder, $currency), 'After partner shares', true],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function partnerData(MonthlyStatement $statement, int $partnerId): array
    {
        $statement->loadMissing('lines');
        $lines = $statement->lines->where('profit_partner_id', $partnerId)->sortBy('merchant_name')->values();
        abort_if($lines->isEmpty(), 404);

        $currency = $statement->base_currency;
        $total = $this->sum($lines);

        return [
            'statement' => $statement,
            'currency' => $currency,
            'monthLabel' => $this->monthLabel($statement->month),
            'closed' => $statement->status === StatementStatus::Closed,
            'partner' => $lines->first()->partner_name,
            'lines' => $lines,
            'total' => $total,
            'tiles' => [
                ['Period', $this->monthLabel($statement->month), $statement->month, false],
                ['Merchants', (string) $lines->pluck('merchant_name')->unique()->count(), 'With a share this month', false],
                ['Your share', PdfRenderer::money($total, $currency), $statement->status === StatementStatus::Closed ? 'Final' : 'Preliminary', true],
            ],
        ];
    }

    private function monthLabel(string $month): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month)->format('F Y');
    }

    /**
     * @param  Collection<int, MonthlyStatementLine>  $lines
     */
    private function sum(Collection $lines): BigDecimal
    {
        return $lines->reduce(fn (BigDecimal $c, MonthlyStatementLine $l) => $c->plus($this->dec($l->share)), BigDecimal::zero());
    }

    private function percentOf(BigDecimal $part, BigDecimal $whole): string
    {
        if ($whole->isZero()) {
            return '—';
        }

        return PdfRenderer::percent($part->multipliedBy(100)->dividedBy($whole, 1, RoundingMode::HalfUp));
    }

    private function dec(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? 0))->toScale(2, RoundingMode::HalfUp);
    }
}

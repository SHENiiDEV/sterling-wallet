<?php

namespace App\Reports\Generation;

use App\Enums\ReportStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use App\Support\PdfRenderer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * One daily report for a client: every completed report of every merchant
 * of every company of the client, per company and MID, with totals per
 * currency. Built from the stored report figures — nothing is recalculated
 * and our provider costs and profit never appear.
 */
class ClientDailyStatement
{
    private const TOTAL_KEYS = ['sales', 'turnover', 'deductions', 'fees', 'reserve', 'payout'];

    /**
     * @param  int|null  $companyId  only this company of the client (default: all of them)
     */
    public function render(Client $client, CarbonImmutable $date, ?int $companyId = null): string
    {
        return PdfRenderer::render('reports.client-daily', $this->data($client, $date, $companyId));
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Client $client, CarbonImmutable $date, ?int $companyId = null): array
    {
        $scope = $companyId === null ? null : Company::query()->where('client_id', $client->id)->findOrFail($companyId);
        $companyIds = $scope === null ? Company::query()->where('client_id', $client->id)->pluck('id')->all() : [$scope->id];

        $tasks = DailyReportTask::query()
            ->with('merchant.company', 'merchantMid.bankProvider')
            ->whereIn('merchant_id', Merchant::query()->whereIn('company_id', $companyIds)->select('id'))
            ->whereDate('report_date', $date->toDateString())
            ->get()
            ->sortBy(fn (DailyReportTask $t) => [$t->merchant->company->name ?? '', $t->merchant->name, $t->merchantMid->mid])
            ->values();

        $done = $tasks->where('status', ReportStatus::Completed);
        $pending = $tasks->where('status', '!=', ReportStatus::Completed)
            ->map(fn (DailyReportTask $t) => ['mid' => $t->merchantMid->mid, 'merchant' => $t->merchant->name, 'status' => $t->status->value])
            ->values()->all();

        $companies = [];
        $totals = [];
        $sums = ['total' => [], 'companies' => []];
        foreach ($done as $task) {
            $company = $task->merchant->company->name ?? $task->merchant->name;
            $row = [
                'merchant' => $task->merchant->name,
                'website' => $task->merchant->website,
                'mid' => $task->merchantMid->mid,
                'label' => $task->merchantMid->label,
                'currency' => $task->currency,
                'sales' => (int) $task->sales_count,
                'declines' => (int) ($task->summary_data['counts']['declines'] ?? 0),
                'refunds' => (int) ($task->summary_data['counts']['refunds'] ?? 0),
                'chargebacks' => (int) ($task->summary_data['counts']['chargebacks'] ?? 0),
                'turnover' => $this->dec($task->turnover),
                'deductions' => $this->dec($task->refunds_amount)->plus($this->dec($task->chargebacks_amount))->plus($this->dec($task->collabs_amount)),
                'fees' => $this->dec($task->total_merchant_fee)->plus($this->dec($task->conversion_fee)),
                'reserve' => $this->dec($task->reserve_amount),
                'payout' => $this->dec($task->net_payout),
            ];

            $companies[$company]['rows'][] = $row;
            $this->add($companies[$company]['totals'][$task->currency], $row);
            $this->add($totals[$task->currency], $row);
            $this->accumulate($sums['companies'][$company][$task->currency], $task);
            $this->accumulate($sums['total'][$task->currency], $task);
        }

        ksort($totals);
        ksort($sums['total']);

        // The same payout calculation as the single-MID statement, per currency and per company.
        $calc = [
            'total' => array_map(fn (array $sum) => $this->steps($sum), $sums['total']),
            'companies' => array_map(fn (array $byCurrency) => array_map(fn (array $sum) => $this->steps($sum), $byCurrency), $sums['companies']),
        ];

        return [
            'client' => $client,
            'scope' => $scope,
            'date' => $date,
            'companies' => $companies,
            'totals' => $totals,
            'calc' => $calc,
            'pending' => $pending,
            'generatedAt' => now()->timezone(config('sterling.timezone', 'Europe/Riga')),
        ];
    }

    public function xlsx(Client $client, CarbonImmutable $date, ?int $companyId = null): string
    {
        $data = $this->data($client, $date, $companyId);

        $rows = [['Company', 'Merchant', 'Website', 'MID', 'Currency', 'Sales', 'Declines', 'Refunds', 'Chargebacks', 'Turnover', 'Refunds & chargebacks amount', 'Fees', 'Reserve', 'Payout']];
        foreach ($data['companies'] as $company => $block) {
            foreach ($block['rows'] as $row) {
                $rows[] = [$company, $row['merchant'], $row['website'], $row['mid'], $row['currency'], $row['sales'], $row['declines'], $row['refunds'], $row['chargebacks'], ...$this->strings($row, ['turnover', 'deductions', 'fees', 'reserve', 'payout'])];
            }
        }
        foreach ($data['totals'] as $currency => $total) {
            $rows[] = ['Total', null, null, null, $currency, $total['sales'], $total['declines'], $total['refunds'], $total['chargebacks'], ...$this->strings($total, ['turnover', 'deductions', 'fees', 'reserve', 'payout'])];
        }

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Daily report');
        $sheet->fromArray([[$client->invoiceName().($data['scope'] ? ' · '.$data['scope']->name : '').' — '.$date->toDateString()], [], ...$rows]);
        foreach (range('A', 'O') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $calc = $book->createSheet()->setTitle('Calculation');
        $lines = [];
        $emit = function (string $title, array $steps) use (&$lines) {
            $lines[] = [$title];
            foreach ($steps as $step) {
                $lines[] = [$step['label'], $step['detail'], (string) $step['amount']];
            }
            $lines[] = [];
        };
        foreach ($data['calc']['total'] as $currency => $steps) {
            $emit(($data['scope'] ? $data['scope']->name : $client->invoiceName()).' · '.$currency, $steps);
        }
        if (count($data['calc']['companies']) > 1) {
            foreach ($data['calc']['companies'] as $company => $byCurrency) {
                foreach ($byCurrency as $currency => $steps) {
                    $emit($company.' · '.$currency, $steps);
                }
            }
        }
        $calc->fromArray($lines);
        foreach (['A', 'B', 'C'] as $column) {
            $calc->getColumnDimension($column)->setAutoSize(true);
        }

        $path = tempnam(sys_get_temp_dir(), 'cli');
        (new Xlsx($book))->save($path);
        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    /**
     * Adds one MID report to a running sum for the payout calculation.
     *
     * @param  array<string, mixed>|null  $sum
     */
    private function accumulate(?array &$sum, DailyReportTask $task): void
    {
        $fee = $task->summary_data['merchant_fee'] ?? [];
        $counts = $task->summary_data['counts'] ?? [];
        $values = [
            'gross' => $this->dec($task->turnover),
            'percent' => $this->dec($fee['percent'] ?? $task->total_merchant_fee),
            'fixed' => $this->dec($fee['fixed'] ?? 0),
            'fx' => $this->dec($fee['fx_markup'] ?? 0),
            'refunds' => $this->dec($task->refunds_amount),
            'chargebacks' => $this->dec($task->chargebacks_amount),
            'collabs' => $this->dec($task->collabs_amount),
            'fee' => $this->dec($task->total_merchant_fee),
            'reserve' => $this->dec($task->reserve_amount),
            'net_volume' => $this->dec($task->net_volume),
            'conversion' => $this->dec($task->conversion_fee),
            'payout' => $this->dec($task->net_payout),
        ];
        $sum ??= [...array_map(fn () => BigDecimal::zero(), $values), 'sales' => 0, 'refunds_count' => 0, 'chargebacks_count' => 0, 'collabs_count' => 0];

        foreach ($values as $key => $value) {
            $sum[$key] = $sum[$key]->plus($value);
        }
        $sum['sales'] += (int) ($counts['sales'] ?? $task->sales_count);
        $sum['refunds_count'] += (int) ($counts['refunds'] ?? 0);
        $sum['chargebacks_count'] += (int) ($counts['chargebacks'] ?? 0);
        $sum['collabs_count'] += (int) ($counts['collabs'] ?? 0);
    }

    /**
     * Gross sales down to net payout, as numbered steps (same as the MID statement).
     *
     * @param  array<string, mixed>  $sum
     * @return list<array{kind: string, label: string, detail: string|null, amount: BigDecimal}>
     */
    private function steps(array $sum): array
    {
        $afterFees = $sum['gross']->minus($sum['refunds'])->minus($sum['chargebacks'])->minus($sum['collabs'])->minus($sum['fee']);

        return [
            ['kind' => 'plus', 'label' => 'Gross sales', 'detail' => $sum['sales'].' approved '.($sum['sales'] === 1 ? 'sale' : 'sales'), 'amount' => $sum['gross']],
            ['kind' => 'minus', 'label' => 'Processing fee', 'detail' => 'Percent fee on sales', 'amount' => $sum['percent']->negated()],
            ['kind' => 'minus', 'label' => 'Transaction fees', 'detail' => 'Success · decline · refund · chargeback', 'amount' => $sum['fixed']->negated()],
            ...($sum['fx']->isZero() ? [] : [['kind' => 'minus', 'label' => 'Settlement FX markup', 'detail' => 'On the amount converted to '.config('sterling.settlement_currency'), 'amount' => $sum['fx']->negated()]]),
            ['kind' => 'minus', 'label' => 'Refunds', 'detail' => $sum['refunds_count'].' refunded', 'amount' => $sum['refunds']->negated()],
            ['kind' => 'minus', 'label' => 'Chargebacks', 'detail' => $sum['chargebacks_count'].' disputed', 'amount' => $sum['chargebacks']->negated()],
            ...($sum['collabs_count'] === 0 && $sum['collabs']->isZero() ? [] : [['kind' => 'minus', 'label' => 'Collaborations', 'detail' => $sum['collabs_count'].' Mastercard collaboration cases', 'amount' => $sum['collabs']->negated()]]),
            ['kind' => 'subtotal', 'label' => 'Net after fees', 'detail' => null, 'amount' => $afterFees],
            ['kind' => 'minus', 'label' => 'Rolling reserve', 'detail' => 'Held per the merchant terms', 'amount' => $sum['reserve']->negated()],
            ['kind' => 'subtotal', 'label' => 'Net volume', 'detail' => null, 'amount' => $sum['net_volume']],
            ['kind' => 'minus', 'label' => 'Conversion fee', 'detail' => 'Fiat → crypto', 'amount' => $sum['conversion']->negated()],
            ['kind' => 'total', 'label' => 'Net payout', 'detail' => 'Added to the next settlement', 'amount' => $sum['payout']],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $total
     * @param  array<string, mixed>  $row
     */
    private function add(?array &$total, array $row): void
    {
        $total ??= ['sales' => 0, 'declines' => 0, 'refunds' => 0, 'chargebacks' => 0, ...array_fill_keys(array_slice(self::TOTAL_KEYS, 1), BigDecimal::zero())];
        $total['sales'] += $row['sales'];
        $total['declines'] += $row['declines'];
        $total['refunds'] += $row['refunds'];
        $total['chargebacks'] += $row['chargebacks'];
        foreach (array_slice(self::TOTAL_KEYS, 1) as $key) {
            $total[$key] = $total[$key]->plus($row[$key]);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function strings(array $values, array $keys): array
    {
        return array_map(fn (string $key) => (string) $values[$key], $keys);
    }

    private function dec(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? 0))->toScale(2, RoundingMode::HalfUp);
    }
}

<?php

namespace App\Reports\Generation;

use App\Enums\ReportStatus;
use App\Models\Client;
use App\Models\DailyReportTask;
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

    public function render(Client $client, CarbonImmutable $date): string
    {
        return PdfRenderer::render('reports.client-daily', $this->data($client, $date));
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Client $client, CarbonImmutable $date): array
    {
        $tasks = DailyReportTask::query()
            ->with('merchant.company', 'merchantMid.bankProvider')
            ->whereIn('merchant_id', $client->merchants()->select('merchants.id'))
            ->whereDate('report_date', $date->toDateString())
            ->get()
            ->sortBy(fn (DailyReportTask $t) => [$t->merchant->company->name ?? '', $t->merchantMid->mid])
            ->values();

        $done = $tasks->where('status', ReportStatus::Completed);
        $pending = $tasks->where('status', '!=', ReportStatus::Completed)
            ->map(fn (DailyReportTask $t) => ['mid' => $t->merchantMid->mid, 'merchant' => $t->merchant->name, 'status' => $t->status->value])
            ->values()->all();

        $companies = [];
        $totals = [];
        foreach ($done as $task) {
            $company = $task->merchant->company->name ?? $task->merchant->name;
            $row = [
                'merchant' => $task->merchant->name,
                'mid' => $task->merchantMid->mid,
                'label' => $task->merchantMid->label,
                'acquirer' => $task->merchantMid->bankProvider?->name,
                'currency' => $task->currency,
                'sales' => (int) $task->sales_count,
                'turnover' => $this->dec($task->turnover),
                'deductions' => $this->dec($task->refunds_amount)->plus($this->dec($task->chargebacks_amount)),
                'fees' => $this->dec($task->total_merchant_fee)->plus($this->dec($task->conversion_fee)),
                'reserve' => $this->dec($task->reserve_amount),
                'payout' => $this->dec($task->net_payout),
            ];

            $companies[$company]['rows'][] = $row;
            $this->add($companies[$company]['totals'][$task->currency], $row);
            $this->add($totals[$task->currency], $row);
        }

        ksort($totals);

        return [
            'client' => $client,
            'date' => $date,
            'companies' => $companies,
            'totals' => $totals,
            'pending' => $pending,
            'generatedAt' => now()->timezone(config('sterling.timezone', 'Europe/Riga')),
        ];
    }

    public function xlsx(Client $client, CarbonImmutable $date): string
    {
        $data = $this->data($client, $date);

        $rows = [['Company', 'Merchant', 'MID', 'Acquirer', 'Currency', 'Sales', 'Turnover', 'Refunds & chargebacks', 'Fees', 'Reserve', 'Payout']];
        foreach ($data['companies'] as $company => $block) {
            foreach ($block['rows'] as $row) {
                $rows[] = [$company, $row['merchant'], $row['mid'], $row['acquirer'], $row['currency'], $row['sales'], ...$this->strings($row, ['turnover', 'deductions', 'fees', 'reserve', 'payout'])];
            }
        }
        foreach ($data['totals'] as $currency => $total) {
            $rows[] = ['Total', null, null, null, $currency, $total['sales'], ...$this->strings($total, ['turnover', 'deductions', 'fees', 'reserve', 'payout'])];
        }

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Daily report');
        $sheet->fromArray([[$client->name.' — '.$date->toDateString()], [], ...$rows]);
        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $path = tempnam(sys_get_temp_dir(), 'cli');
        (new Xlsx($book))->save($path);
        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    /**
     * @param  array<string, mixed>|null  $total
     * @param  array<string, mixed>  $row
     */
    private function add(?array &$total, array $row): void
    {
        $total ??= ['sales' => 0, ...array_fill_keys(array_slice(self::TOTAL_KEYS, 1), BigDecimal::zero())];
        $total['sales'] += $row['sales'];
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

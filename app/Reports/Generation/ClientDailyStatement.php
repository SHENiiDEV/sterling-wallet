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
                'declines' => (int) ($task->summary_data['counts']['declines'] ?? 0),
                'refunds' => (int) ($task->summary_data['counts']['refunds'] ?? 0),
                'chargebacks' => (int) ($task->summary_data['counts']['chargebacks'] ?? 0),
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
            'scope' => $scope,
            'date' => $date,
            'companies' => $companies,
            'totals' => $totals,
            'pending' => $pending,
            'generatedAt' => now()->timezone(config('sterling.timezone', 'Europe/Riga')),
        ];
    }

    public function xlsx(Client $client, CarbonImmutable $date, ?int $companyId = null): string
    {
        $data = $this->data($client, $date, $companyId);

        $rows = [['Company', 'Merchant', 'MID', 'Acquirer', 'Currency', 'Sales', 'Declines', 'Refunds', 'Chargebacks', 'Turnover', 'Refunds & chargebacks amount', 'Fees', 'Reserve', 'Payout']];
        foreach ($data['companies'] as $company => $block) {
            foreach ($block['rows'] as $row) {
                $rows[] = [$company, $row['merchant'], $row['mid'], $row['acquirer'], $row['currency'], $row['sales'], $row['declines'], $row['refunds'], $row['chargebacks'], ...$this->strings($row, ['turnover', 'deductions', 'fees', 'reserve', 'payout'])];
            }
        }
        foreach ($data['totals'] as $currency => $total) {
            $rows[] = ['Total', null, null, null, $currency, $total['sales'], $total['declines'], $total['refunds'], $total['chargebacks'], ...$this->strings($total, ['turnover', 'deductions', 'fees', 'reserve', 'payout'])];
        }

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Daily report');
        $sheet->fromArray([[$client->name.($data['scope'] ? ' · '.$data['scope']->name : '').' — '.$date->toDateString()], [], ...$rows]);
        foreach (range('A', 'N') as $column) {
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

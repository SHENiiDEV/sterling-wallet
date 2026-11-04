<?php

namespace App\Reports\Generation;

use App\Enums\ProviderType;
use App\Models\DailyReportTask;
use App\Models\MerchantOperation;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes the files a finished daily report ships with:
 * a summary XLSX, the merchant statement as PDF, and a CSV of its operations.
 */
class ReportDocuments
{
    private const OPERATION_COLUMNS = [
        'provider' => 'Provider', 'operation_type' => 'Type', 'reference' => 'Reference', 'payment_id' => 'Payment ID',
        'sp_id' => 'sp_id', 'arn' => 'ARN', 'card_bin' => 'BIN', 'card_last4' => 'Last 4', 'ips' => 'Scheme',
        'region' => 'Region', 'amount' => 'Amount', 'currency' => 'Currency', 'transaction_at' => 'Time (UTC)',
        'customer_name' => 'Customer', 'customer_account' => 'Customer account',
        'customer_email' => 'Email', 'resolution' => 'Resolution',
    ];

    public function __construct(private DailyStatement $statement) {}

    public function write(DailyReportTask $task): void
    {
        $disk = Storage::disk(config('sterling.reports.disk'));
        $base = sprintf('reports/generated/%s/%s_%s', $task->report_date->toDateString(), preg_replace('/[^A-Za-z0-9_-]/', '_', $task->merchantMid->mid), $task->report_date->toDateString());

        $summary = $this->summary($task);

        $disk->put("{$base}.xlsx", $this->xlsx($task, $summary));
        $disk->put("{$base}.pdf", $this->statement->render($task));
        $disk->put("{$base}_operations.csv", $this->csv($task));

        $task->update([
            'generated_xlsx_path' => "{$base}.xlsx",
            'generated_pdf_path' => "{$base}.pdf",
            'generated_operations_path' => "{$base}_operations.csv",
        ]);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function summary(DailyReportTask $task): array
    {
        $money = fn (string $field) => (string) Money::of($task->{$field} ?? 0, $task->currency, roundingMode: RoundingMode::HalfUp)->getAmount();
        $counts = $task->summary_data['counts'] ?? [];

        return [
            ['Merchant', $task->merchant->name],
            ['MID', $task->merchantMid->mid],
            ['Currency', $task->currency],
            ['Report date', $task->report_date->toDateString()],
            ['Period', $task->period_from->toDateString().' — '.$task->period_to->toDateString()],
            ['Sales', (string) ($counts['sales'] ?? $task->sales_count)],
            ['Turnover', $money('turnover')],
            ['Refunds', $money('refunds_amount').' ('.($counts['refunds'] ?? 0).')'],
            ['Chargebacks', $money('chargebacks_amount').' ('.($counts['chargebacks'] ?? 0).')'],
            ...((float) ($task->collabs_amount ?? 0) > 0 ? [['Collaborations', $money('collabs_amount').' ('.($counts['collabs'] ?? 0).')']] : []),
            ['Declines', (string) ($counts['declines'] ?? 0)],
            ['Processing fee', $money('total_merchant_fee')],
            ['Rolling reserve', $money('reserve_amount')],
            ['Net volume', $money('net_volume')],
            ['Conversion fee', $money('conversion_fee')],
            ['Payout', $money('net_payout')],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $summary
     */
    private function xlsx(DailyReportTask $task, array $summary): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Summary');
        $sheet->fromArray($summary);
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);

        $ops = $book->createSheet()->setTitle('Operations');
        $ops->fromArray([array_values(self::OPERATION_COLUMNS), ...$this->operationRows($task)]);

        $path = tempnam(sys_get_temp_dir(), 'rep');
        (new Xlsx($book))->save($path);
        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    private function csv(DailyReportTask $task): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_values(self::OPERATION_COLUMNS), escape: '');
        foreach ($this->operationRows($task) as $row) {
            fputcsv($handle, $row, escape: '');
        }
        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    /**
     * One row per transaction, built from the acquirer's file with the gateway's invoice id (cpi_…) glued on as
     * `sp_id`. Acquirer rows without a pair keep an empty sp_id; gateway rows no acquirer row matches are listed
     * after them, with their own cpi_ and the acquirer columns empty.
     *
     * @return list<list<string|null>>
     */
    private function operationRows(DailyReportTask $task): array
    {
        $label = config('sterling.documents.provider_label');

        return MerchantOperation::query()
            ->where('merchant_mid_id', $task->merchant_mid_id)
            ->whereBetween('report_date', [$task->period_from->toDateString(), $task->period_to->toDateString()])
            ->where(fn ($q) => $q->where('role', ProviderType::Bank->value)->orWhere(fn ($g) => $g->where('role', ProviderType::Gate->value)->whereNull('matched_operation_id')))
            ->orderByRaw('role = ? desc', [ProviderType::Bank->value])->orderBy('transaction_at')->orderBy('id')
            ->get()
            ->map(function (MerchantOperation $op) use ($label) {
                $gateOnly = $op->role === ProviderType::Gate;

                return [
                    $label, $op->operation_type->value, $op->reference, $gateOnly ? null : $op->payment_id,
                    $gateOnly ? $op->payment_id : $op->sp_id, $op->getAttribute('arn'), $op->card_bin, $op->card_last4, $op->ips,
                    $op->region, $op->amount, $op->currency, $op->transaction_at?->toDateTimeString(),
                    $op->customerName(), $op->customerAccount(), $op->customer_email, $op->getAttribute('resolution'),
                ];
            })
            ->all();
    }
}

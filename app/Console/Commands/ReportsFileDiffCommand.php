<?php

namespace App\Console\Commands;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Models\DailyReportSource;
use App\Models\DailyReportTask;
use App\Models\MerchantOperation;
use App\Reports\Parsers\ParserRegistry;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use SplFileInfo;

#[Signature('reports:file-diff {--bank=cardaq : Provider code of the acquirer} {--mid= : Only this MID (merchant_mids.mid)} {--from= : First report date (Y-m-d)} {--to= : Last report date (Y-m-d)} {--only-diff : Show only the reports that differ}')]
#[Description('Compare each report\'s sales with the sales in the acquirer file stored for it (what the bank pays), with the payout difference to correct in the next settlement')]
class ReportsFileDiffCommand extends Command
{
    public function handle(ParserRegistry $parsers): int
    {
        $reports = DailyReportTask::query()
            ->with(['merchantMid', 'sources.provider'])
            ->where('status', ReportStatus::Completed)
            ->whereHas('sources.provider', fn ($q) => $q->where('code', $this->option('bank'))->where('type', ProviderType::Bank->value))
            ->when($this->option('mid'), fn ($q, $mid) => $q->whereHas('merchantMid', fn ($m) => $m->where('mid', $mid)))
            ->when($this->option('from'), fn ($q, $from) => $q->where('report_date', '>=', $from))
            ->when($this->option('to'), fn ($q, $to) => $q->where('report_date', '<=', $to))
            ->orderBy('report_date')
            ->get();

        $disk = Storage::disk(config('sterling.reports.disk'));
        $rows = [];
        $total = BigDecimal::zero();

        foreach ($reports as $report) {
            /** @var DailyReportSource|null $source */
            $source = $report->sources->first(fn (DailyReportSource $s) => $s->provider->code === $this->option('bank'));
            if ($source === null || ! $source->file_path || ! $disk->exists($source->file_path)) {
                continue;
            }

            [$count, $amount] = $this->fileSales($parsers, $source, $report->merchantMid->mid, $disk->path($source->file_path));
            $diff = $amount->minus((string) $report->turnover)->toScale(2, RoundingMode::HalfUp);
            if ($this->option('only-diff') && $diff->isZero()) {
                continue;
            }

            // The payout moves with the sales roughly in the report's own proportion (fees and reserve are percentages).
            $ratio = BigDecimal::of((string) $report->turnover)->isZero() ? BigDecimal::zero() : BigDecimal::of((string) $report->net_payout)->dividedBy((string) $report->turnover, 8, RoundingMode::HalfUp);
            $payoutDiff = $diff->multipliedBy($ratio)->toScale(2, RoundingMode::HalfUp);
            $locked = $report->lockReason();
            $total = $locked ? $total->plus($payoutDiff) : $total;

            $rows[] = [
                $report->report_date->toDateString(), $report->merchantMid->mid, $report->currency,
                $report->sales_count.' / '.number_format((float) $report->turnover, 2, '.', ''),
                $count.' / '.number_format((float) (string) $amount, 2, '.', ''),
                (string) $diff, $locked ? (string) $payoutDiff : '—', $locked ? 'locked' : 'open',
            ];
        }

        $this->table(['Report', 'MID', 'Cur', 'Report sales (n / amount)', 'File sales (n / amount)', 'Sales diff', '≈ Payout diff', 'State'], $rows);
        $this->line('Sales diff = file − report. A locked report (in an approved or paid settlement, or with its reserve released) is not rebuilt: its payout diff is to be corrected in the next settlement with an Adjustment.');
        $this->info('Locked reports, ≈ payout to correct in total: '.$total->toScale(2, RoundingMode::HalfUp));

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: BigDecimal} count and amount of the file's sales of this MID
     */
    private function fileSales(ParserRegistry $parsers, DailyReportSource $source, string $mid, string $path): array
    {
        $provider = $source->provider;
        $count = 0;
        $amount = BigDecimal::zero();

        foreach ($parsers->for($provider)->rows(new SplFileInfo($path), $provider->timezone ?: config('sterling.timezone')) as $row) {
            if ((string) $row->mid !== $mid) {
                continue;
            }

            $type = MerchantOperation::classify([
                'trn_type' => $row->trnType, 'amount' => $row->amount, 'decline_fee' => $row->fees['decline_fee'] ?? null,
                'processing_code' => $row->processingCode, 'resolution' => $row->resolution,
            ]);
            if ($type === OperationType::Sale) {
                $count++;
                $amount = $amount->plus(BigDecimal::of((string) $row->amount)->abs());
            }
        }

        return [$count, $amount];
    }
}

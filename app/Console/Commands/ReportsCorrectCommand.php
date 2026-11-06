<?php

namespace App\Console\Commands;

use App\Enums\ReportStatus;
use App\Models\DailyReportTask;
use App\Reports\Generation\DailyReportGenerator;
use App\Reports\Generation\ReportBlocked;
use App\Services\AuditLogger;
use Brick\Math\BigDecimal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * A report that was paid with figures calculated too early or on incomplete data: recalculates it and rebuilds its
 * PDF / XLSX / CSV so the client has the right documents. The settlement that paid it stays exactly as it was; the
 * difference is paid or taken back with an adjustment in a later settlement.
 */
#[Signature('reports:correct {--mid= : MID number (merchant_mids.mid)} {--from= : First report date (Y-m-d)} {--to= : Last report date (Y-m-d)} {--apply : Do it; without it only shows what would change}')]
#[Description('Recalculate reports that are already in an approved or paid settlement and rebuild their documents; the settlement is not changed')]
class ReportsCorrectCommand extends Command
{
    public function handle(DailyReportGenerator $generator): int
    {
        if (! $this->option('mid')) {
            $this->error('Give --mid=<MID number> (and --from / --to).');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');

        $reports = DailyReportTask::query()
            ->with('merchantMid:id,mid,currency')
            ->where('status', ReportStatus::Completed)
            ->whereHas('merchantMid', fn ($m) => $m->where('mid', $this->option('mid')))
            ->when($this->option('from'), fn ($q, $from) => $q->where('report_date', '>=', $from))
            ->when($this->option('to'), fn ($q, $to) => $q->where('report_date', '<=', $to))
            ->orderBy('report_date')
            ->get();

        $rows = [];
        $total = BigDecimal::zero();
        foreach ($reports as $report) {
            $reason = $report->lockReason();
            if ($reason === null) {
                $this->line("Skipped {$report->report_date->toDateString()}: not paid, use reports:regenerate.");

                continue;
            }
            if (str_contains($reason, 'reserve has already been released')) {
                $this->warn("Skipped {$report->report_date->toDateString()}: {$reason} Its reserve cannot be rebooked.");

                continue;
            }

            try {
                ['before' => $before, 'after' => $after] = $generator->preview($report);
            } catch (ReportBlocked $e) {
                $this->warn("Skipped {$report->report_date->toDateString()}: {$e->getMessage()}");

                continue;
            }
            $diff = BigDecimal::of($after['payout'])->minus($before['payout']);
            $total = $total->plus($diff);
            $rows[] = [$report->report_date->toDateString(), $reason, "{$before['payout']} → {$after['payout']}", ($diff->isPositive() ? '+' : '').$diff->toScale(2)];

            if ($apply) {
                $generator->correctPaid($report);
                AuditLogger::log('report.corrected', $report, ['payout_before' => $before['payout'], 'payout_after' => $after['payout'], 'difference' => (string) $diff->toScale(2)]);
            }
        }

        if ($rows === []) {
            $this->info('Nothing to correct.');

            return self::SUCCESS;
        }

        $this->table(['Report', 'In', 'Payout paid → correct', 'Difference'], $rows);
        $currency = $reports->first()->merchantMid->currency->value;
        $sign = $total->isPositive() ? '+' : '';
        if ($apply) {
            $this->info('Corrected '.count($rows).' report(s) and rebuilt their documents. The settlements are unchanged.');
            $this->line("Add an Adjustment of {$sign}{$total->toScale(2)} {$currency} to the next settlement of the merchant: plus pays the merchant what was left out, minus takes back what was overpaid.");
        } else {
            $this->warn("Dry run. Difference in total {$sign}{$total->toScale(2)} {$currency}. Add --apply to correct the reports and rebuild their documents.");
        }

        return self::SUCCESS;
    }
}

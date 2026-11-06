<?php

namespace App\Console\Commands;

use App\Enums\ReportStatus;
use App\Models\DailyReportTask;
use App\Reports\Generation\DailyReportGenerator;
use App\Reports\Generation\ReportBlocked;
use Brick\Math\BigDecimal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Recalculates reports on paper: what each would be with today's operations and terms, against what is stored and
 * (possibly) already paid. Locked reports are included on purpose and nothing is changed or saved.
 */
#[Signature('reports:recalc-preview {--mid= : MID number (merchant_mids.mid)} {--from= : First report date (Y-m-d)} {--to= : Last report date (Y-m-d)}')]
#[Description('Show what completed reports would be if recalculated now, next to the stored figures (read only, works for paid reports)')]
class ReportsRecalcPreviewCommand extends Command
{
    public function handle(DailyReportGenerator $generator): int
    {
        if (! $this->option('mid')) {
            $this->error('Give --mid=<MID number>.');

            return self::FAILURE;
        }

        $reports = DailyReportTask::query()
            ->with('merchantMid:id,mid,currency')
            ->where('status', ReportStatus::Completed)
            ->whereHas('merchantMid', fn ($m) => $m->where('mid', $this->option('mid')))
            ->when($this->option('from'), fn ($q, $from) => $q->where('report_date', '>=', $from))
            ->when($this->option('to'), fn ($q, $to) => $q->where('report_date', '<=', $to))
            ->orderBy('report_date')
            ->get();

        if ($reports->isEmpty()) {
            $this->warn('No completed reports for this MID and range.');

            return self::SUCCESS;
        }

        $rows = [];
        $total = BigDecimal::zero();
        foreach ($reports as $report) {
            try {
                ['before' => $b, 'after' => $a] = $generator->preview($report);
            } catch (ReportBlocked $e) {
                $this->warn("{$report->report_date->toDateString()}: {$e->getMessage()}");

                continue;
            }

            $diff = BigDecimal::of($a['payout'])->minus($b['payout']);
            $total = $total->plus($diff);
            $rows[] = [
                $report->report_date->toDateString(),
                "{$b['sales']} → {$a['sales']}",
                "{$b['turnover']} → {$a['turnover']}",
                "{$b['fees']} → {$a['fees']}",
                "{$b['reserve']} → {$a['reserve']}",
                "{$b['payout']} → {$a['payout']}",
                ($diff->isPositive() ? '+' : '').$diff->toScale(2),
                $report->lockReason() ? 'locked' : '',
            ];
        }

        $this->table(['Report', 'Sales', 'Turnover', 'Merchant fees', 'Reserve', 'Payout stored → now', 'Difference', ''], $rows);
        $currency = $reports->first()->merchantMid->currency->value;
        $this->line('Payout difference in total: '.($total->isPositive() ? '+' : '').$total->toScale(2)." {$currency}");
        $this->line('Plus = the merchant was paid too little (we owe it); minus = paid too much (to take from the next settlement). Nothing was changed.');

        return self::SUCCESS;
    }
}

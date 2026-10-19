<?php

namespace App\Console\Commands;

use App\Enums\ReportStatus;
use App\Jobs\GenerateDailyReportJob;
use App\Models\DailyReportTask;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Recalculates finished reports (e.g. after bank terms changed). Reports that
 * are in an approved / settled settlement or whose reserve was released stay as they are.
 */
#[Signature('reports:regenerate {--bank= : Only MIDs of this bank (provider code)} {--mid= : Only this MID (merchant_mids.mid)} {--from= : First report date (Y-m-d)} {--to= : Last report date (Y-m-d)} {--apply : Queue the regeneration (without it only counts)}')]
#[Description('Recalculate completed daily reports with the current terms, skipping locked ones; nothing is e-mailed')]
class RegenerateReportsCommand extends Command
{
    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $reports = DailyReportTask::query()
            ->with('merchantMid:id,mid')
            ->where('status', ReportStatus::Completed)
            ->when($this->option('bank'), fn ($q, $code) => $q->whereHas('merchantMid.bankProvider', fn ($p) => $p->where('code', $code)))
            ->when($this->option('mid'), fn ($q, $mid) => $q->whereHas('merchantMid', fn ($m) => $m->where('mid', $mid)))
            ->when($this->option('from'), fn ($q, $from) => $q->where('report_date', '>=', $from))
            ->when($this->option('to'), fn ($q, $to) => $q->where('report_date', '<=', $to))
            ->orderBy('report_date')
            ->get();

        $queued = 0;
        $locked = 0;
        foreach ($reports as $report) {
            if ($reason = $report->lockReason()) {
                $locked++;
                $this->line("Skipped #{$report->id} MID {$report->merchantMid?->mid} {$report->report_date->toDateString()}: {$reason}");

                continue;
            }

            $queued++;
            if ($apply) {
                $report->update(['status' => ReportStatus::Pending, 'error_log' => null]);
                GenerateDailyReportJob::dispatch($report->id, false);
            }
        }

        $this->info(($apply ? 'Queued' : 'Would queue')." {$queued} report(s), {$locked} locked skipped.");
        if (! $apply && $queued > 0) {
            $this->line('Run again with --apply to do it.');
        }

        return self::SUCCESS;
    }
}

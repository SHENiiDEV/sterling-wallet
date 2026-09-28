<?php

namespace App\Console\Commands;

use App\Enums\ReportStatus;
use App\Models\DailyReportTask;
use App\Reports\Generation\ReportDocuments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Re-renders PDF / XLSX / CSV of completed reports from their stored
 * numbers — nothing is recalculated, so locked (settled) reports are safe.
 */
#[Signature('reports:rebuild-documents {--from= : First report date (Y-m-d)} {--to= : Last report date (Y-m-d)}')]
#[Description('Re-render the files of completed daily reports without recalculating them')]
class RebuildReportDocumentsCommand extends Command
{
    public function handle(ReportDocuments $documents): int
    {
        $query = DailyReportTask::query()
            ->with('merchant', 'merchantMid')
            ->where('status', ReportStatus::Completed)
            ->when($this->option('from'), fn ($q, $from) => $q->where('report_date', '>=', $from))
            ->when($this->option('to'), fn ($q, $to) => $q->where('report_date', '<=', $to))
            ->orderBy('id');

        $done = 0;
        $query->each(function (DailyReportTask $task) use ($documents, &$done) {
            $documents->write($task);
            $done++;
        });

        $this->info("Rebuilt the documents of {$done} reports.");

        return self::SUCCESS;
    }
}

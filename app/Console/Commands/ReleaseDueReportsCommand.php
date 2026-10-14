<?php

namespace App\Console\Commands;

use App\Enums\ReportStatus;
use App\Models\DailyReportTask;
use App\Reports\Ingestion\ReportIngestionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reports:release-due')]
#[Description('Build the reports whose files are all in and whose day is now over')]
class ReleaseDueReportsCommand extends Command
{
    public function handle(ReportIngestionService $ingestion): int
    {
        $released = 0;

        DailyReportTask::query()
            ->with('merchantMid.bankProvider')
            ->where('status', ReportStatus::Pending)
            ->each(function (DailyReportTask $task) use ($ingestion, &$released) {
                if ($task->missingProviderIds() === [] && ! $task->dayIsOpen()) {
                    $ingestion->advance($task);
                    $released++;
                }
            });

        $this->info("Released {$released} report(s).");

        return self::SUCCESS;
    }
}

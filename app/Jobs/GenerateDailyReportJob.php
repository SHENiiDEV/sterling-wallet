<?php

namespace App\Jobs;

use App\Enums\ReportStatus;
use App\Models\DailyReportTask;
use App\Reports\Generation\DailyReportGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class GenerateDailyReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $taskId, public bool $sendEmail = true) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->taskId))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(DailyReportGenerator $generator): void
    {
        $task = DailyReportTask::query()->find($this->taskId);

        if ($task !== null) {
            $generator->generate($task, $this->sendEmail);
        }
    }

    public function failed(?Throwable $exception): void
    {
        DailyReportTask::query()->whereKey($this->taskId)->update([
            'status' => ReportStatus::Failed,
            'error_log' => $exception?->getMessage(),
        ]);
    }
}

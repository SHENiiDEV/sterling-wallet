<?php

namespace App\Jobs;

use App\Bots\BotAlerts;
use App\Bots\BotResult;
use App\Bots\ConnectorRegistry;
use App\Bots\Target;
use App\Enums\BotRunStatus;
use App\Models\BotRun;
use App\Reports\Ingestion\ReportIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Runs one bot target and feeds what it downloaded into ingestion.
 * Retries and the run journal live here, not in the Node scripts.
 */
class RunConnectorJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [120, 600];

    public int $timeout = 1200;

    public function __construct(public int $runId) {}

    /**
     * One browser per account at a time: portals dislike parallel logins.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        $run = BotRun::query()->find($this->runId);

        return [(new WithoutOverlapping('bot-account:'.($run->integration_account_id ?? $this->runId)))
            ->releaseAfter(60)->expireAfter($this->timeout + 60)];
    }

    public function handle(ConnectorRegistry $connectors, ReportIngestionService $ingestion): void
    {
        $run = BotRun::query()->with('account.provider')->find($this->runId);
        if ($run === null || $run->account === null) {
            return;
        }

        $run->update(['status' => BotRunStatus::Running, 'attempts' => $run->attempts + 1, 'started_at' => now(), 'error' => null]);
        $started = hrtime(true);

        $target = Target::fromArray($run->account, $run->target ?? []);
        $result = $connectors->get($run->connector)->run($target, $run);

        $run->fill([
            'log' => $result->log !== '' ? mb_substr($result->log, -20000) : null,
            'screenshot_path' => $result->screenshot,
            'files' => $result->files,
            'rows_count' => $result->rows,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);

        if ($result->status === BotRunStatus::Failed) {
            // Back to queued until the queue gives up; failed() then marks it failed.
            $run->fill(['status' => BotRunStatus::Queued, 'error' => $result->error ?? 'Bot failed without a message.'])->save();

            throw new RuntimeException($run->error);
        }

        if ($result->status === BotRunStatus::Succeeded) {
            $this->ingest($run, $target, $result, $ingestion);
        }

        $run->fill([
            'status' => $result->status,
            'error' => $result->status === BotRunStatus::Skipped ? $result->error : null,
            'finished_at' => now(),
        ])->save();
    }

    private function ingest(BotRun $run, Target $target, BotResult $result, ReportIngestionService $ingestion): void
    {
        $provider = $run->account->provider;
        $date = $result->reportDate ?? $target->period->reportDate;

        if ($result->isEmptyReport()) {
            $ingestion->markEmpty($provider, $date, $target->mids, $run);

            return;
        }

        $rows = 0;
        foreach ($result->files as $path) {
            if (! is_file($path)) {
                throw new RuntimeException("Downloaded file is missing: {$path}");
            }
            $rows += $ingestion->ingest($provider, new SplFileInfo($path), $date, $run, $target->mids)->rows;
        }
        $run->rows_count ??= $rows;
    }

    public function failed(?Throwable $exception): void
    {
        $run = BotRun::query()->with('account')->find($this->runId);
        if ($run === null) {
            return;
        }

        $run->update([
            'status' => BotRunStatus::Failed,
            'error' => $run->error ?? $exception?->getMessage(),
            'finished_at' => now(),
        ]);

        app(BotAlerts::class)->afterFailure($run);
    }
}

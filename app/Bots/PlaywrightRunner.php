<?php

namespace App\Bots;

use App\Enums\BotRunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;

/**
 * Runs bots/{script}.mjs with a JSON payload on stdin. The script logs to
 * stderr and prints one JSON object as the last line of stdout:
 * {"status": "succeeded|failed|skipped", "files": [...], "rows": n,
 *  "report_date": "Y-m-d", "error": "...", "screenshot": "/path.png"}.
 */
class PlaywrightRunner
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function run(string $script, array $input): BotResult
    {
        $path = rtrim((string) config('sterling.bots.scripts_path'), '/').'/'.$script.'.mjs';

        $process = Process::path(dirname($path))
            ->timeout((int) config('sterling.bots.timeout_seconds'))
            ->input((string) json_encode($input))
            ->run([(string) config('sterling.bots.node_binary'), $path]);

        $log = trim($process->errorOutput());
        $lines = array_values(array_filter(array_map('trim', explode("\n", $process->output()))));
        $payload = json_decode((string) end($lines), true);

        if (! is_array($payload)) {
            return new BotResult(
                BotRunStatus::Failed,
                error: 'Bot script gave no result (exit code '.$process->exitCode().'). '.mb_substr($log ?: $process->output(), -2000),
                log: $log,
            );
        }

        return new BotResult(
            BotRunStatus::tryFrom((string) ($payload['status'] ?? '')) ?? BotRunStatus::Failed,
            files: array_values(array_filter((array) ($payload['files'] ?? []), 'is_string')),
            rows: isset($payload['rows']) ? (int) $payload['rows'] : null,
            reportDate: ! empty($payload['report_date']) ? CarbonImmutable::parse($payload['report_date']) : null,
            error: $payload['error'] ?? null,
            screenshot: $payload['screenshot'] ?? null,
            log: $log,
        );
    }
}

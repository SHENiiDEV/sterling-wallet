<?php

namespace App\Console\Commands;

use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Jobs\GenerateDailyReportJob;
use App\Models\DailyReportTask;
use App\Models\Provider;
use App\Reports\Ingestion\ReportIngestionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use SplFileInfo;

/**
 * Reads the acquirer files already stored for reports again, so that each file is booked as a whole to its
 * own day (and not split by transaction day as before). Reports in approved or paid settlements are not rebuilt.
 */
#[Signature('reports:reingest-bank {--bank=cardaq : Provider code of the acquirer} {--mid= : Only reports of this MID (merchant_mids.mid)} {--from= : First report date (Y-m-d)} {--to= : Last report date (Y-m-d)} {--apply : Do it; without it only lists the files}')]
#[Description('Re-read the stored acquirer files of a period so each file belongs wholly to its day; locked reports stay as they are')]
class ReingestBankFilesCommand extends Command
{
    public function handle(ReportIngestionService $ingestion): int
    {
        $provider = Provider::query()->where('code', $this->option('bank'))->where('type', ProviderType::Bank->value)->first();
        if ($provider === null) {
            $this->error('No acquirer with that code.');

            return self::FAILURE;
        }

        $reports = DailyReportTask::query()
            ->with(['merchantMid:id,mid', 'sources'])
            ->when($this->option('mid'), fn ($q, $mid) => $q->whereHas('merchantMid', fn ($m) => $m->where('mid', $mid)))
            ->when($this->option('from'), fn ($q, $from) => $q->where('report_date', '>=', $from))
            ->when($this->option('to'), fn ($q, $to) => $q->where('report_date', '<=', $to))
            ->whereHas('sources', fn ($q) => $q->where('provider_id', $provider->id)->whereNotNull('file_path'))
            ->orderBy('report_date')
            ->get();

        $disk = Storage::disk(config('sterling.reports.disk'));
        $files = $reports
            ->flatMap(fn (DailyReportTask $r) => $r->sources->where('provider_id', $provider->id)->pluck('file_path'))
            ->filter(fn (?string $path) => $path && $disk->exists($path) && preg_match('/\.(csv|xlsx|xls)$/i', $path))
            ->unique()
            ->values();

        foreach ($files as $path) {
            $this->line(($this->option('apply') ? 'Reading ' : 'Would read ').$path);
        }

        if (! $this->option('apply')) {
            $this->warn("{$files->count()} file(s). Run again with --apply to re-read them.");

            return self::SUCCESS;
        }

        $rows = 0;
        foreach ($files as $path) {
            $result = $ingestion->ingest($provider, new SplFileInfo($disk->path($path)));
            $rows += $result->rows;
            $this->line("  {$result->rows} row(s) into ".count($result->tasks).' report(s)'.($result->reportDate ? ' · day '.$result->reportDate->toDateString() : ''));
        }

        // Reports that lost payments to another day (the Friday–Sunday bundle, for one) are built again; locked ones are skipped.
        $queued = 0;
        $locked = 0;
        DailyReportTask::query()
            ->where('status', ReportStatus::Completed)
            ->whereIn('id', $reports->pluck('id'))
            ->get()
            ->each(function (DailyReportTask $report) use (&$queued, &$locked) {
                if ($report->lockReason() !== null) {
                    $locked++;

                    return;
                }
                $report->update(['status' => ReportStatus::Pending, 'error_log' => null]);
                GenerateDailyReportJob::dispatch($report->id, false);
                $queued++;
            });

        $this->info("{$rows} row(s) read. {$queued} report(s) queued for a rebuild, {$locked} locked left as they are.");

        return self::SUCCESS;
    }
}

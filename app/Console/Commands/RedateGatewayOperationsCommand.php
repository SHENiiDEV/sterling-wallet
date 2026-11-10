<?php

namespace App\Console\Commands;

use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Jobs\GenerateDailyReportJob;
use App\Models\DailyReportTask;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Reports\Ingestion\ReportIngestionService;
use App\Reports\ReportDateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Gateway payments that were booked to the day of the run that brought them instead of the day they were made on:
 * moves each to the report day of its own payment time (in the gateway's time zone, weekends bundled like the
 * reports) and rebuilds the reports that are not paid yet. Paid reports are listed, not touched.
 */
#[Signature('operations:redate-gateway {--mid= : MID number (merchant_mids.mid)} {--from= : First report date (Y-m-d)} {--to= : Last report date (Y-m-d)} {--apply : Move them; without it only counts}')]
#[Description('Book gateway payments to the day they were made on and rebuild the reports that are not paid yet')]
class RedateGatewayOperationsCommand extends Command
{
    public function handle(ReportDateResolver $dates, ReportIngestionService $ingestion): int
    {
        $apply = (bool) $this->option('apply');
        $zones = Provider::query()->pluck('timezone', 'id');

        $mids = MerchantMid::query()->with('gateProvider')->when($this->option('mid'), fn ($q, $mid) => $q->where('mid', $mid))->whereNotNull('gate_provider_id')->get();

        $moves = [];
        $left = [];
        $counts = [];
        foreach ($mids as $mid) {
            MerchantOperation::query()
                ->where('merchant_mid_id', $mid->id)
                ->where('role', ProviderType::Gate->value)
                ->whereNotNull('transaction_at')
                ->when($this->option('from'), fn ($q, $from) => $q->where('report_date', '>=', $from))
                ->when($this->option('to'), fn ($q, $to) => $q->where('report_date', '<=', $to))
                ->select(['id', 'provider_id', 'transaction_at', 'report_date'])
                ->lazyById(2000)
                ->each(function (MerchantOperation $op) use ($dates, $zones, $mid, &$moves, &$left, &$counts) {
                    $zone = $zones[$op->provider_id] ?: config('sterling.timezone');
                    $day = $op->transaction_at->copy()->setTimezone($zone)->toDateString();
                    $new = $dates->periodFor($day)->reportDate->toDateString();
                    $old = $op->report_date?->toDateString();
                    if ($new !== $old) {
                        $moves[$mid->id][$new][] = $op->id;
                        $left[$mid->id][(string) $old] = true;
                        $counts["{$mid->mid} {$old} → {$new}"] = ($counts["{$mid->mid} {$old} → {$new}"] ?? 0) + 1;
                    }
                });
        }

        if ($moves === []) {
            $this->info('Every gateway payment is already on its own day.');

            return self::SUCCESS;
        }

        ksort($counts);
        $this->table(['MID  from → to', 'Payments'], array_map(fn ($k, $n) => [$k, $n], array_keys($counts), $counts));
        $total = array_sum($counts);
        if (! $apply) {
            $this->warn("Dry run: {$total} payment(s) would move. Add --apply to do it.");

            return self::SUCCESS;
        }

        $affected = [];
        foreach ($moves as $midId => $byDate) {
            $mid = $mids->firstWhere('id', $midId);
            foreach ($byDate as $new => $ids) {
                foreach (array_chunk($ids, 1000) as $chunk) {
                    MerchantOperation::query()->whereIn('id', $chunk)->update(['report_date' => $new]);
                }
                $ingestion->ensureReport($mid->gateProvider, $mid, CarbonImmutable::parse($new));
                $affected[$midId][$new] = true;
            }
            // The days they left too.
            foreach (array_keys($left[$midId] ?? []) as $old) {
                if ($old !== '') {
                    $affected[$midId][$old] = true;
                }
            }
        }

        $paid = [];
        $rebuilt = 0;
        foreach ($affected as $midId => $days) {
            $tasks = DailyReportTask::query()->where('merchant_mid_id', $midId)->whereIn('report_date', array_keys($days))->get();
            foreach ($tasks as $task) {
                if ($reason = $task->lockReason()) {
                    $paid[] = "MID {$mids->firstWhere('id', $midId)->mid} {$task->report_date->toDateString()}: {$reason}";

                    continue;
                }
                $task->update(['status' => ReportStatus::Pending, 'error_log' => null]);
                GenerateDailyReportJob::dispatch($task->id, false);
                $rebuilt++;
            }
        }

        $this->info("Moved {$total} payment(s); {$rebuilt} report(s) queued for a rebuild.");
        if ($paid !== []) {
            $this->warn('Paid reports whose gateway figures changed (not touched, check them with reports:recalc-preview):');
            foreach ($paid as $line) {
                $this->line("  {$line}");
            }
        }

        return self::SUCCESS;
    }
}

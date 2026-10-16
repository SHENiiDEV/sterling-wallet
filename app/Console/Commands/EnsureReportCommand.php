<?php

namespace App\Console\Commands;

use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Reports\Ingestion\ReportIngestionService;
use App\Reports\ReportDateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reports:ensure {mid : MID number (merchant_mids.mid)} {date : Report day, YYYY-MM-DD}')]
#[Description('Create the daily report of a MID for a day that has operations but no report (e.g. operations added by hand), and build it if the day is over')]
class EnsureReportCommand extends Command
{
    public function handle(ReportIngestionService $ingestion, ReportDateResolver $dates): int
    {
        $mid = MerchantMid::query()->where('mid', $this->argument('mid'))->first();
        if ($mid === null) {
            $this->error('No MID with this number.');

            return self::FAILURE;
        }

        $period = $dates->periodFor(CarbonImmutable::parse((string) $this->argument('date')));
        $providerIds = MerchantOperation::query()
            ->where('merchant_mid_id', $mid->id)
            ->whereBetween('report_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->distinct()
            ->pluck('provider_id');

        if ($providerIds->isEmpty()) {
            $this->warn("MID {$mid->mid} has no operations for {$period->reportDate->toDateString()}.");

            return self::FAILURE;
        }

        $task = null;
        foreach (Provider::query()->whereIn('id', $providerIds)->get() as $provider) {
            $task = $ingestion->ensureReport($provider, $mid, $period->reportDate);
        }

        $ingestion->advance($task);
        $this->info("Report #{$task->id} for {$period->reportDate->toDateString()}: {$task->fresh()->status->value}.");

        return self::SUCCESS;
    }
}

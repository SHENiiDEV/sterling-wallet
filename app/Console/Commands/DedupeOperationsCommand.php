<?php

namespace App\Console\Commands;

use App\Bots\BotAlerts;
use App\Jobs\GenerateDailyReportJob;
use App\Models\DailyReportTask;
use App\Models\MerchantOperation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The safety net behind the load: a payment is one operation. If the same provider has the same payment id, type and
 * amount twice on a MID (a file loaded twice in two layouts, a late file with a payment already there), the fuller
 * record is kept and the others are removed, and the reports of those days are built again. Without --apply it only
 * lists them, and tells (Telegram) when there are some. A report that is already in a settlement is never touched:
 * it is named, to be corrected with reports:correct.
 */
#[Signature('operations:dedupe {--apply : Remove the duplicates and rebuild the reports; without it only lists them} {--notify : Send a message when duplicates are found}')]
#[Description('Find the same payment stored twice and keep the fuller record')]
class DedupeOperationsCommand extends Command
{
    public function handle(): int
    {
        $groups = MerchantOperation::query()->toBase()
            ->selectRaw('provider_id, merchant_mid_id, payment_id, operation_type, amount, count(*) as n')
            ->groupBy('provider_id', 'merchant_mid_id', 'payment_id', 'operation_type', 'amount')
            ->havingRaw('count(*) > 1')->limit(500)->get();

        if ($groups->isEmpty()) {
            $this->info('No payment is stored twice.');

            return self::SUCCESS;
        }

        $rows = [];
        $removed = 0;
        $touched = [];
        $locked = [];

        foreach ($groups as $group) {
            $copies = MerchantOperation::query()
                ->where('provider_id', $group->provider_id)->where('merchant_mid_id', $group->merchant_mid_id)
                ->where('payment_id', $group->payment_id)->where('operation_type', $group->operation_type)->where('amount', $group->amount)
                ->orderBy('id')->get();
            $keep = $this->fullest($copies);
            $extra = $copies->where('id', '!=', $keep->id);

            foreach ($extra as $copy) {
                $report = DailyReportTask::query()->where('merchant_mid_id', $copy->merchant_mid_id)
                    ->where('period_from', '<=', $copy->report_date)->where('period_to', '>=', $copy->report_date)->first();
                $isLocked = $report?->lockReason() !== null;
                $rows[] = [$copy->merchant_mid_id, $copy->payment_id, $copy->operation_type->value, $copy->amount, $copy->id, $keep->id, $copy->report_date->toDateString(), $isLocked ? 'in a settlement' : ($this->option('apply') ? 'removed' : 'to remove')];

                if ($isLocked) {
                    $locked[] = $copy->merchant_mid_id.' '.$copy->report_date->toDateString();

                    continue;
                }
                if ($this->option('apply')) {
                    $copy->delete();
                    $removed++;
                    foreach ([$report, DailyReportTask::query()->where('merchant_mid_id', $keep->merchant_mid_id)->where('period_from', '<=', $keep->report_date)->where('period_to', '>=', $keep->report_date)->first()] as $task) {
                        if ($task !== null) {
                            $touched[$task->id] = $task;
                        }
                    }
                }
            }
        }

        $this->table(['MID id', 'Payment', 'Type', 'Amount', 'Extra id', 'Kept id', 'Day', 'State'], $rows);
        foreach ($touched as $task) {
            GenerateDailyReportJob::dispatch($task->id, false);
        }
        $this->line($this->option('apply') ? "Removed {$removed}; ".count($touched).' report(s) are being built again.' : 'Dry run. Add --apply to remove them.');
        if ($locked !== []) {
            $this->warn('In a settlement (not touched, correct with reports:correct): '.implode(', ', array_unique($locked)));
        }

        if ($this->option('notify') && ! $this->option('apply')) {
            app(BotAlerts::class)->notify('Duplicate payments found: '.count($rows).' extra record(s). Run operations:dedupe on the server.');
        }

        return self::SUCCESS;
    }

    /**
     * The record that carries the most: a time, a type, the acquirer's own data; the older one when equal.
     *
     * @param  Collection<int, MerchantOperation>  $copies
     */
    private function fullest(Collection $copies): MerchantOperation
    {
        return $copies->sortByDesc(fn (MerchantOperation $o) => [
            $o->transaction_at !== null ? 1 : 0,
            ($o->trn_type !== null && $o->trn_type !== '') ? 1 : 0,
            count(array_filter((array) $o->raw, fn ($v) => $v !== null && $v !== '')),
            -$o->id,
        ])->first();
    }
}

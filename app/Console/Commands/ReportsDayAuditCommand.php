<?php

namespace App\Console\Commands;

use App\Enums\OperationType;
use App\Models\DailyReportTask;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Why a report's figures are not what they were: what is on its days by side and type, which operations were loaded
 * or changed after the first load, and which pairs disagree on the amount. Nothing is changed.
 */
#[Signature('reports:day-audit {--mid= : MID number (merchant_mids.mid)} {--date= : Report date (Y-m-d)} {--since= : Only operations loaded or changed after this time (Y-m-d H:i, UTC)}')]
#[Description('Show what is on a report day, what was loaded or changed late and which pairs disagree on the amount (read only)')]
class ReportsDayAuditCommand extends Command
{
    public function handle(): int
    {
        $mid = MerchantMid::query()->where('mid', $this->option('mid'))->first();
        $report = $mid && $this->option('date') ? DailyReportTask::query()->where('merchant_mid_id', $mid->id)->where('report_date', $this->option('date'))->first() : null;
        if ($report === null) {
            $this->error('Give --mid=<MID number> and --date=<report date of an existing report>.');

            return self::FAILURE;
        }

        $operations = MerchantOperation::query()
            ->where('merchant_mid_id', $mid->id)
            ->whereBetween('report_date', [$report->period_from->toDateString(), $report->period_to->toDateString()])
            ->orderBy('transaction_at')->get();

        $this->info("MID {$mid->mid} · report {$report->report_date->toDateString()} ({$report->period_from->toDateString()} – {$report->period_to->toDateString()}) · {$operations->count()} operations");
        $this->line("Stored: sales {$report->sales_count}, turnover {$report->getRawOriginal('turnover')}, payout {$report->getRawOriginal('net_payout')}, generated {$report->generated_at}");

        $this->table(['Side', 'Type', 'Count', 'Amount'], $operations->groupBy(fn (MerchantOperation $o) => $o->role->value.'|'.$o->operation_type->value)->map(function ($group, string $key) {
            [$role, $type] = explode('|', $key);

            return [$role, $type, $group->count(), number_format($group->sum(fn (MerchantOperation $o) => abs((float) $o->getRawOriginal('amount'))), 2, '.', '')];
        })->values()->all());

        // The loads: how many operations each day of loading brought, to see a late file at a glance.
        $this->line('Operations by the day they were loaded:');
        $this->table(['Loaded', 'Side', 'Count'], $operations->groupBy(fn (MerchantOperation $o) => $o->created_at?->format('Y-m-d').'|'.$o->role->value)->map(fn ($g, string $k) => [...explode('|', $k), $g->count()])->sortKeys()->values()->all());

        $since = $this->option('since') ? CarbonImmutable::parse((string) $this->option('since'), 'UTC') : null;
        $late = $operations->filter(fn (MerchantOperation $o) => $since !== null
            ? ($o->created_at > $since || $o->updated_at > $since)
            : ($o->updated_at !== null && $o->created_at !== null && $o->updated_at->diffInMinutes($o->created_at) > 5));
        if ($late->isNotEmpty()) {
            $this->warn(($since ? "Loaded or changed after {$since->format('Y-m-d H:i')}" : 'Changed more than five minutes after they were loaded').': '.$late->count());
            $this->table(['Id', 'Side', 'Type', 'Amount', 'Time (UTC)', 'Card', 'Payment ID', 'Loaded', 'Changed'], $late->take(40)->map(fn (MerchantOperation $o) => [
                $o->id, $o->role->value, $o->operation_type->value, number_format(abs((float) $o->getRawOriginal('amount')), 2, '.', ''), $o->transaction_at?->format('Y-m-d H:i'),
                $o->card_mask, $o->payment_id, $o->created_at?->format('Y-m-d H:i'), $o->updated_at?->format('Y-m-d H:i'),
            ])->all());
        }

        $differ = [];
        foreach ($operations->where('operation_type', OperationType::Sale)->whereNotNull('matched_operation_id') as $bank) {
            if ($bank->role->value !== 'bank') {
                continue;
            }
            $gate = MerchantOperation::query()->find($bank->matched_operation_id);
            if ($gate !== null && abs(abs((float) $bank->getRawOriginal('amount')) - abs((float) $gate->getRawOriginal('amount'))) > 0.005) {
                $differ[] = [$bank->id, $bank->payment_id, number_format(abs((float) $bank->getRawOriginal('amount')), 2, '.', ''), $gate->payment_id, number_format(abs((float) $gate->getRawOriginal('amount')), 2, '.', ''), $bank->card_mask.' / '.$gate->card_mask];
            }
        }
        if ($differ !== []) {
            $this->warn(count($differ).' pair(s) where the bank and the gateway disagree on the amount:');
            $this->table(['Bank id', 'Bank payment', 'Bank amount', 'Gateway id', 'Gateway amount', 'Cards'], array_slice($differ, 0, 40));
        }

        $big = $operations->where('operation_type', OperationType::Sale)->sortByDesc(fn (MerchantOperation $o) => abs((float) $o->getRawOriginal('amount')))->take(5);
        $this->line('Largest sales:');
        $this->table(['Side', 'Amount', 'Time (UTC)', 'Card', 'Payment ID', 'Loaded'], $big->map(fn (MerchantOperation $o) => [$o->role->value, number_format(abs((float) $o->getRawOriginal('amount')), 2, '.', ''), $o->transaction_at?->format('Y-m-d H:i'), $o->card_mask, $o->payment_id, $o->created_at?->format('Y-m-d H:i')])->values()->all());

        return self::SUCCESS;
    }
}

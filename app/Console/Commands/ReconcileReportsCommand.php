<?php

namespace App\Console\Commands;

use App\Enums\OperationType;
use App\Models\MerchantMid;
use App\Reports\Reconciliation\ReconciliationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reports:reconcile {--mid= : Only this MID (merchant_mids.mid)} {--days= : Look back this many days}')]
#[Description('Pair acquirer and gateway operations of MIDs that still have unmatched ones')]
class ReconcileReportsCommand extends Command
{
    public function handle(ReconciliationService $reconciler): int
    {
        $days = (int) ($this->option('days') ?: config('sterling.reports.reconcile_lookback_days'));
        $from = now()->subDays($days)->toDateString();
        $to = now()->toDateString();

        $mids = MerchantMid::query()
            ->whereNotNull('bank_provider_id')
            ->whereNotNull('gate_provider_id')
            ->when($this->option('mid'), fn ($q, $mid) => $q->where('mid', $mid))
            ->whereHas('operations', fn ($q) => $q
                ->whereNull('matched_operation_id')
                ->where('operation_type', '!=', OperationType::Decline)
                ->where('report_date', '>=', $from))
            ->get();

        $total = 0;
        foreach ($mids as $mid) {
            $total += $pairs = $reconciler->reconcile($mid, $from, $to);
            if ($pairs > 0) {
                $this->line("MID {$mid->mid}: {$pairs} new pair(s)");
            }
        }

        $this->info("Reconciled {$mids->count()} MID(s), {$total} new pair(s).");

        return self::SUCCESS;
    }
}

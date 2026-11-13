<?php

namespace App\Console\Commands;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Models\DailyReportTask;
use App\Models\MerchantOperation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Operations that may not have been paid: a report that is in a settlement (approved or paid) keeps the figures it
 * had when it was built, so an operation the acquirer sent afterwards sits on the day but not in the payout. For
 * every such report this compares the stored sales, refunds and chargebacks with the operations on its days now and
 * lists the ones that came after the report was generated. Nothing is changed.
 */
#[Signature('reports:late-operations {--bank= : Only MIDs of this bank (provider code)} {--mid= : Only this MID (merchant_mids.mid)} {--from= : First report date (Y-m-d)} {--to= : Last report date (Y-m-d)}')]
#[Description('List operations that sit on a report already in a settlement but are not in its figures (read only)')]
class ReportsLateOperationsCommand extends Command
{
    private const TYPES = [
        'sales' => OperationType::Sale,
        'refunds' => OperationType::Refund,
        'chargebacks' => OperationType::Chargeback,
    ];

    public function handle(): int
    {
        $reports = DailyReportTask::query()
            ->with('merchantMid:id,mid,bank_provider_id,gate_provider_id')
            ->where('status', ReportStatus::Completed)
            ->when($this->option('mid'), fn ($q, $mid) => $q->whereHas('merchantMid', fn ($m) => $m->where('mid', $mid)))
            ->when($this->option('bank'), fn ($q, $bank) => $q->whereHas('merchantMid.bankProvider', fn ($p) => $p->where('code', $bank)))
            ->when($this->option('from'), fn ($q, $from) => $q->where('report_date', '>=', $from))
            ->when($this->option('to'), fn ($q, $to) => $q->where('report_date', '<=', $to))
            ->orderBy('report_date')->orderBy('merchant_mid_id')
            ->get()
            ->filter(fn (DailyReportTask $r) => $r->lockReason() !== null);

        $this->info($reports->count().' report(s) in a settlement checked.');
        $summary = [];
        $late = [];

        foreach ($reports as $report) {
            $live = MerchantOperation::query()
                ->where('merchant_mid_id', $report->merchant_mid_id)->where('provider_id', $report->merchantMid->bank_provider_id)->where('role', ProviderType::Bank)
                ->whereBetween('report_date', [$report->period_from->toDateString(), $report->period_to->toDateString()])
                ->get();

            $differs = false;
            $cells = [];
            foreach (self::TYPES as $key => $type) {
                $count = $live->where('operation_type', $type)->count();
                $stored = (int) ($report->summary_data['counts'][$key] ?? ($key === 'sales' ? $report->sales_count : 0));
                $cells[] = $count === $stored ? (string) $count : "{$stored} → {$count}";
                $differs = $differs || $count !== $stored;
            }
            $turnover = $live->where('operation_type', OperationType::Sale)->reduce(fn (BigDecimal $c, MerchantOperation $o) => $c->plus(BigDecimal::of((string) $o->getRawOriginal('amount'))->abs()), BigDecimal::zero());
            $storedTurnover = BigDecimal::of((string) $report->getRawOriginal('turnover'));
            $differs = $differs || ! $turnover->toScale(2, RoundingMode::HalfUp)->isEqualTo($storedTurnover->toScale(2, RoundingMode::HalfUp));

            if (! $differs) {
                continue;
            }

            $summary[] = [$report->merchantMid->mid, $report->report_date->toDateString(), ...$cells, $storedTurnover->toScale(2).' → '.$turnover->toScale(2), $report->lockReason()];

            foreach ($live->filter(fn (MerchantOperation $o) => $report->generated_at === null || $o->created_at > $report->generated_at)->whereIn('operation_type', array_values(self::TYPES)) as $operation) {
                $pair = $operation->matched_operation_id ? MerchantOperation::query()->find($operation->matched_operation_id) : null;
                $late[] = [
                    $report->merchantMid->mid, $report->report_date->toDateString(), $operation->operation_type->value, $operation->amount, $operation->currency,
                    $operation->transaction_at?->format('Y-m-d H:i'), $operation->card_mask, $pair?->payment_id ?? $operation->payment_id, $operation->created_at?->format('Y-m-d H:i'),
                ];
            }
        }

        if ($summary === []) {
            $this->info('Every report in a settlement agrees with the operations on its days.');

            return self::SUCCESS;
        }

        $this->table(['MID', 'Report', 'Sales', 'Refunds', 'Chargebacks', 'Turnover paid → now', 'In'], $summary);
        $this->warn('Operations that came after the report was generated (not in what was paid):');
        $this->table(['MID', 'Report', 'Type', 'Amount', 'Cur', 'Time (UTC)', 'Card', 'sp_id / payment id', 'Loaded'], $late);
        $this->line('Correct a report with: reports:correct --mid=<MID> --from=<date> --to=<date> --apply, then add the difference as an Adjustment to the next settlement.');

        return self::SUCCESS;
    }
}

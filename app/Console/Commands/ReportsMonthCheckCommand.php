<?php

namespace App\Console\Commands;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Models\DailyReportTask;
use App\Models\MerchantOperation;
use App\Reports\TerminalVolumes;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Why the dashboard and Volumes show different totals for a month: per terminal, what the gateway saw (Volumes), what
 * the acquirer completed (its files) and what the finished daily reports add up to (the dashboard). Read only.
 */
#[Signature('reports:month-check {--month= : Month, YYYY-MM (default: last month)}')]
#[Description('Per terminal: gateway volume (Volumes) against the acquirer files and the finished reports (dashboard) for a month')]
class ReportsMonthCheckCommand extends Command
{
    public function handle(TerminalVolumes $volumes): int
    {
        $month = $this->option('month') ?: CarbonImmutable::now(config('sterling.timezone'))->subMonth()->format('Y-m');
        $from = CarbonImmutable::createFromFormat('Y-m-d', "{$month}-01", config('sterling.timezone'))->startOfMonth();
        $to = $from->endOfMonth()->startOfDay();

        $rows = [];
        $totals = ['gate' => 0.0, 'gate_n' => 0, 'bank' => 0.0, 'reports' => 0.0, 'done' => 0, 'open' => 0];
        foreach ($volumes->rows($from, $to) as $row) {
            $reports = DailyReportTask::query()->toBase()
                ->where('merchant_mid_id', $row['id'])
                ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()])
                ->selectRaw('status, count(*) as n, coalesce(sum(turnover), 0) as turnover')
                ->groupBy('status')->get()->keyBy('status');
            $done = $reports->get(ReportStatus::Completed->value);
            $open = (int) $reports->except(ReportStatus::Completed->value)->sum('n');

            $bank = (float) MerchantOperation::query()->toBase()
                ->where('merchant_mid_id', $row['id'])
                ->where('role', ProviderType::Bank->value)
                ->where('operation_type', OperationType::Sale->value)
                ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()])
                ->sum('amount');

            $reportsTurnover = (float) ($done->turnover ?? 0);
            $rows[] = [
                $row['mid'], $row['merchant']['name'],
                number_format($row['volume'], 2).' ('.$row['approved'].')',
                number_format($bank, 2),
                number_format($reportsTurnover, 2).' ('.(int) ($done->n ?? 0).')',
                $open,
                number_format($row['volume'] - $reportsTurnover, 2),
            ];
            $totals['gate'] += $row['volume'];
            $totals['gate_n'] += $row['approved'];
            $totals['bank'] += $bank;
            $totals['reports'] += $reportsTurnover;
            $totals['done'] += (int) ($done->n ?? 0);
            $totals['open'] += $open;
        }

        $this->table(['MID', 'Merchant', 'Gateway volume (Volumes)', 'Acquirer sales', 'Finished reports (dashboard)', 'Reports not finished', 'Gateway − reports'], $rows);
        $this->line(sprintf(
            'Total %s: gateway %s (%d payments), acquirer files %s, finished reports %s (%d), not finished: %d.',
            $month, number_format($totals['gate'], 2), $totals['gate_n'], number_format($totals['bank'], 2), number_format($totals['reports'], 2), $totals['done'], $totals['open'],
        ));
        $this->line('Volumes counts what the gateway accepted by payment day. The dashboard adds up only finished reports (the acquirer file is in) by report day: a day without its bank file, or a Fri–Sun report dated Sunday, is not in the month.');

        return self::SUCCESS;
    }
}

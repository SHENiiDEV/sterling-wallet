<?php

namespace App\Console\Commands;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Models\DailyReportTask;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Reports\Generation\Tariff;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Per-transaction fees (success, decline, refund, chargeback) recounted from the operations as they are now,
 * against what each stored report charged. For reports that were calculated before their gateway data arrived and are
 * already paid: shows what was not deducted, to take off the next settlement. Nothing is changed.
 */
#[Signature('reports:missing-fees {--mid= : MID number (merchant_mids.mid)} {--from= : First report date (Y-m-d)} {--to= : Last report date (Y-m-d)}')]
#[Description('Transaction fees a stored report did not charge, counted again from the current operations (read only)')]
class ReportsMissingFeesCommand extends Command
{
    public function handle(): int
    {
        $mid = MerchantMid::query()->where('mid', (string) $this->option('mid'))->first();
        if ($mid === null) {
            $this->error('Give --mid=<MID number>.');

            return self::FAILURE;
        }

        $tariff = Tariff::forMid($mid);
        $rows = [];
        $total = BigDecimal::zero();

        $reports = DailyReportTask::query()
            ->where('merchant_mid_id', $mid->id)
            ->where('status', ReportStatus::Completed)
            ->when($this->option('from'), fn ($q, $d) => $q->where('report_date', '>=', $d))
            ->when($this->option('to'), fn ($q, $d) => $q->where('report_date', '<=', $d))
            ->orderBy('report_date')
            ->get();

        foreach ($reports as $report) {
            $ops = MerchantOperation::query()
                ->where('merchant_mid_id', $mid->id)
                ->whereBetween('report_date', [$report->period_from->toDateString(), $report->period_to->toDateString()])
                ->get();
            $bank = $ops->where('provider_id', $mid->bank_provider_id)->where('role', ProviderType::Bank);
            $gate = $mid->gate_provider_id ? $ops->where('provider_id', $mid->gate_provider_id)->where('role', ProviderType::Gate) : collect();
            $source = $mid->gate_provider_id ? $gate : $bank;

            $now = $tariff->fixed('success')->multipliedBy($source->where('operation_type', OperationType::Sale)->count())
                ->plus($tariff->fixed('decline')->multipliedBy($source->where('operation_type', OperationType::Decline)->count()))
                ->plus($tariff->fixed('refund')->multipliedBy($bank->where('operation_type', OperationType::Refund)->count()))
                ->plus($tariff->fixed('chargeback')->multipliedBy($bank->where('operation_type', OperationType::Chargeback)->count()))
                ->toScale(2, RoundingMode::HalfUp);
            $stored = BigDecimal::of($report->summary_data['merchant_fee']['fixed'] ?? 0)->toScale(2, RoundingMode::HalfUp);
            $diff = $now->minus($stored);
            if ($diff->isZero()) {
                continue;
            }

            $settlement = $report->settlementLines()->with('settlement:id,number,status')->get()
                ->map->settlement->filter(fn ($s) => $s !== null && $s->status->value !== 'cancelled')->first();
            $rows[] = [$report->report_date->toDateString(), (string) $stored, (string) $now, (string) $diff, $settlement?->number.' '.($settlement?->status->value ?? 'unpaid')];
            $total = $total->plus($diff);
        }

        if ($rows === []) {
            $this->info("MID {$mid->mid}: every stored report already charges what the current operations give.");

            return self::SUCCESS;
        }

        $this->table(['Report', 'Charged', 'Should be', 'Not charged', 'Settlement'], $rows);
        $this->line("Not charged in total: {$total} {$mid->currency->value} (per-transaction fees only; the reserve and conversion on it are small).");

        return self::SUCCESS;
    }
}

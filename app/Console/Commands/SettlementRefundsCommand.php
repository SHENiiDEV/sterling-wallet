<?php

namespace App\Console\Commands;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Enums\SettlementLineType;
use App\Models\MerchantOperation;
use App\Models\Settlement;
use Brick\Math\BigDecimal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The transactions behind the "Refunds + CB" column of a settlement statement: for every report in it, the refunds,
 * chargebacks and Mastercard collaboration cases of its days, with the ids the merchant can search by. Nothing is changed.
 */
#[Signature('settlement:refunds {number : Settlement number, e.g. SET-2026-000053}')]
#[Description('List the refunds, chargebacks and collaboration cases behind a settlement statement (read only)')]
class SettlementRefundsCommand extends Command
{
    private const TYPES = [OperationType::Refund, OperationType::Chargeback, OperationType::Collab];

    public function handle(): int
    {
        $settlement = Settlement::query()->where('number', $this->argument('number'))->first();
        if ($settlement === null) {
            $this->error('No settlement with this number.');

            return self::FAILURE;
        }

        $rows = [];
        $total = BigDecimal::zero();
        $reports = $settlement->lines()->where('type', SettlementLineType::Report)->with('dailyReport.merchantMid')->get()->pluck('dailyReport')->filter()->sortBy('report_date');

        foreach ($reports as $report) {
            $stored = BigDecimal::of((string) $report->getRawOriginal('refunds_amount'))
                ->plus((string) $report->getRawOriginal('chargebacks_amount'))
                ->plus((string) $report->getRawOriginal('collabs_amount'));
            if ($stored->isZero()) {
                continue;
            }

            $operations = MerchantOperation::query()
                ->where('merchant_mid_id', $report->merchant_mid_id)->where('provider_id', $report->merchantMid->bank_provider_id)->where('role', ProviderType::Bank)
                ->whereIn('operation_type', array_map(fn (OperationType $t) => $t->value, self::TYPES))
                ->whereBetween('report_date', [$report->period_from->toDateString(), $report->period_to->toDateString()])
                ->orderBy('transaction_at')->get();

            foreach ($operations as $operation) {
                $pair = $operation->matched_operation_id ? MerchantOperation::query()->find($operation->matched_operation_id) : null;
                $original = $operation->raw['original_operation_id'] ?? null;
                $source = $original ? MerchantOperation::query()->find($original) : null;
                $rows[] = [
                    $report->report_date->toDateString(), $report->merchantMid->mid, $operation->operation_type->value,
                    BigDecimal::of((string) $operation->getRawOriginal('amount'))->abs()->toScale(2), $operation->currency,
                    $operation->transaction_at?->format('Y-m-d H:i'), $operation->card_mask,
                    $operation->payment_id, $operation->arn ?? '', $operation->sp_id ?? $pair?->payment_id ?? $source?->sp_id ?? $source?->matchedOperation?->payment_id ?? '',
                ];
            }
            $total = $total->plus($stored);
            $this->line("{$report->report_date->toDateString()} · MID {$report->merchantMid->mid}: refunds + chargebacks + collaborations in the statement {$stored->toScale(2)} {$report->currency}");
        }

        if ($rows === []) {
            $this->info("{$settlement->number}: no refunds, chargebacks or collaboration cases in its reports.");

            return self::SUCCESS;
        }

        $this->table(['Report', 'MID', 'Type', 'Amount', 'Cur', 'Time (UTC)', 'Card', 'Payment ID', 'ARN', 'sp_id / paired id'], $rows);
        $this->info("Total on the statement under \"Refunds + CB\": {$total->toScale(2)}");

        return self::SUCCESS;
    }
}

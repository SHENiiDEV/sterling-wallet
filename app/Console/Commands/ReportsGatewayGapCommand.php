<?php

namespace App\Console\Commands;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Why the gateway and the acquirer disagree on a MID over some days: the sales of each side by day, and every gateway
 * sale of the period that has no acquirer sale beside it in the same period, with what the acquirer has instead
 * (nothing, a decline, a payment of another day). Nothing is changed.
 */
#[Signature('reports:gateway-gap {--mid= : MID number (merchant_mids.mid)} {--from= : First report date (Y-m-d)} {--to= : Last report date (Y-m-d)}')]
#[Description('List the gateway sales of a period that the acquirer did not complete, and what it has instead (read only)')]
class ReportsGatewayGapCommand extends Command
{
    public function handle(): int
    {
        $mid = MerchantMid::query()->where('mid', $this->option('mid'))->first();
        if ($mid === null || ! $this->option('from') || ! $this->option('to')) {
            $this->error('Give --mid=<MID number>, --from=<Y-m-d> and --to=<Y-m-d>.');

            return self::FAILURE;
        }
        [$from, $to] = [(string) $this->option('from'), (string) $this->option('to')];

        $days = [];
        foreach ([ProviderType::Bank, ProviderType::Gate] as $role) {
            $perDay = MerchantOperation::query()->toBase()
                ->where('merchant_mid_id', $mid->id)->where('role', $role->value)->where('operation_type', OperationType::Sale->value)
                ->whereBetween('report_date', [date('Y-m-d', strtotime($from.' -1 day')), date('Y-m-d', strtotime($to.' +1 day'))])
                ->selectRaw('report_date, count(*) as n, sum(amount) as total')->groupBy('report_date')->orderBy('report_date')->get();
            foreach ($perDay as $row) {
                $days[substr((string) $row->report_date, 0, 10)][$role->value] = $row->n.' · '.number_format((float) $row->total, 2, '.', '');
            }
        }
        ksort($days);
        $this->table(['Day', 'Bank sales', 'Gateway sales'], collect($days)->map(fn (array $d, string $day) => [$day, $d['bank'] ?? '—', $d['gate'] ?? '—'])->values()->all());

        $rows = [];
        $sum = 0.0;
        MerchantOperation::query()
            ->where('merchant_mid_id', $mid->id)->where('role', ProviderType::Gate)->where('operation_type', OperationType::Sale)
            ->whereBetween('report_date', [$from, $to])->orderBy('transaction_at')->get()
            ->each(function (MerchantOperation $gate) use (&$rows, &$sum, $from, $to) {
                $bank = $gate->matched_operation_id ? MerchantOperation::query()->find($gate->matched_operation_id) : null;
                $day = $bank?->report_date?->toDateString();
                if ($bank !== null && $bank->operation_type === OperationType::Sale && $day >= $from && $day <= $to) {
                    return;
                }
                $sum += abs((float) $gate->getRawOriginal('amount'));
                $rows[] = [
                    $gate->payment_id, number_format(abs((float) $gate->getRawOriginal('amount')), 2, '.', ''), $gate->transaction_at?->format('Y-m-d H:i'), $gate->card_mask,
                    $bank === null ? 'no pair' : $bank->operation_type->value.($bank->resolution ? ' '.$bank->resolution : '').' · day '.$day,
                ];
            });

        if ($rows === []) {
            $this->info("Every gateway sale of {$from} – {$to} has an acquirer sale in the same period.");

            return self::SUCCESS;
        }

        $this->warn(count($rows).' gateway sale(s) without an acquirer sale in the period, '.number_format($sum, 2, '.', '').' in total:');
        $this->table(['Gateway id (sp_id)', 'Amount', 'Time (UTC)', 'Card', 'Acquirer has'], $rows);

        return self::SUCCESS;
    }
}

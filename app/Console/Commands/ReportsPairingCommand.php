<?php

namespace App\Console\Commands;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('reports:pairing {--mid= : MID number (merchant_mids.mid)} {--days=30 : Look back this many days}')]
#[Description('Why sales of a terminal have no pair: per day counts, and whether unpaired rows of both sides could pair at all')]
class ReportsPairingCommand extends Command
{
    public function handle(): int
    {
        $mids = MerchantMid::query()
            ->whereNotNull('bank_provider_id')->whereNotNull('gate_provider_id')
            ->when($this->option('mid'), fn ($q, $mid) => $q->where('mid', $mid))
            ->get();

        $from = now()->subDays((int) $this->option('days'))->toDateString();

        foreach ($mids as $mid) {
            $sales = MerchantOperation::query()
                ->where('merchant_mid_id', $mid->id)
                ->where('operation_type', OperationType::Sale)
                ->where('report_date', '>=', $from)
                ->get(['id', 'role', 'report_date', 'matched_operation_id', 'card_bin', 'card_last4', 'amount', 'transaction_at']);

            $unpaired = $sales->whereNull('matched_operation_id');
            if ($unpaired->isEmpty()) {
                continue;
            }

            $this->info("MID {$mid->mid} ({$mid->bankProvider?->name} ↔ {$mid->gateProvider?->name})");

            $rows = $sales->groupBy(fn (MerchantOperation $op) => $op->report_date->toDateString())->sortKeys()->map(function (Collection $day, string $date) {
                $cell = fn (ProviderType $role) => $day->where('role', $role)->count().' / '.$day->where('role', $role)->whereNull('matched_operation_id')->count();

                return [$date, $cell(ProviderType::Bank), $cell(ProviderType::Gate)];
            })->values()->all();
            $this->table(['Report day', 'Bank: sales / unpaired', 'Gateway: sales / unpaired'], $rows);

            $this->diagnose($unpaired);
        }

        return self::SUCCESS;
    }

    /**
     * Unpaired rows of the two sides: do they share a card and an amount, and how far apart in time are they?
     *
     * @param  Collection<int, MerchantOperation>  $unpaired
     */
    private function diagnose(Collection $unpaired): void
    {
        $key = fn (MerchantOperation $op) => $op->card_bin.'|'.$op->card_last4.'|'.number_format(abs((float) $op->amount), 2, '.', '');
        $gate = $unpaired->where('role', ProviderType::Gate)->filter(fn (MerchantOperation $op) => $op->card_bin !== null && $op->transaction_at !== null)->groupBy($key);
        $bank = $unpaired->where('role', ProviderType::Bank)->filter(fn (MerchantOperation $op) => $op->card_bin !== null && $op->transaction_at !== null);

        $offsets = [];
        $same = 0;
        foreach ($bank as $op) {
            $candidates = $gate->get($key($op));
            if ($candidates === null) {
                continue;
            }
            $same++;
            $offsets[] = round(($op->transaction_at->getTimestamp() - $candidates->sortBy(fn ($g) => abs($op->transaction_at->getTimestamp() - $g->transaction_at->getTimestamp()))->first()->transaction_at->getTimestamp()) / 3600, 1);
        }

        $this->line("Unpaired bank rows with the same card and amount among unpaired gateway rows: {$same} of {$bank->count()}.");
        if ($offsets !== []) {
            $histogram = array_count_values(array_map('strval', $offsets));
            arsort($histogram);
            $this->line('Time of the bank row minus the gateway row, hours (count): '.implode(', ', array_map(fn ($h, $n) => "{$h} ({$n})", array_keys(array_slice($histogram, 0, 6, true)), array_slice($histogram, 0, 6, true))));
            $this->line('Pairing takes a gap of up to 5 minutes after a shift of whole hours up to ±3, so a gap near a whole number of hours is fine and anything else is not.');
        }
        if ($same === 0 && $bank->isNotEmpty() && $gate->isNotEmpty()) {
            $this->warn('No unpaired bank row shares card and amount with an unpaired gateway row: look at the card fields (BIN / last 4) of both sides.');
        }
        $this->newLine();
    }
}

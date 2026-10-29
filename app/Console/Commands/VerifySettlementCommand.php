<?php

namespace App\Console\Commands;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Enums\SettlementLineType;
use App\Models\DailyReportTask;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Reports\Generation\Tariff;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Checks a settlement from the ground up: its own arithmetic (lines × rates = total), that each report line carries
 * its report's payout, and that each report's figures follow from its operations and the tariffs when counted again.
 * Nothing is changed.
 */
#[Signature('settlements:verify {settlement : Settlement id or number (SET-2026-000027)}')]
#[Description('Recount a settlement and its reports from the operations and compare with what is stored')]
class VerifySettlementCommand extends Command
{
    private int $failures = 0;

    public function handle(): int
    {
        $key = (string) $this->argument('settlement');
        $settlement = Settlement::query()->with(['merchant', 'client', 'lines.dailyReport'])->where(fn ($q) => $q->where('number', $key)->orWhere('id', ctype_digit($key) ? (int) $key : 0))->first();
        if ($settlement === null) {
            $this->error('No such settlement.');

            return self::FAILURE;
        }

        $this->info("{$settlement->number} · {$settlement->status->value} · ".($settlement->client?->name ?? $settlement->merchant->name)." · payout {$settlement->payout_currency}");
        $this->checkArithmetic($settlement);

        foreach ($settlement->lines->where('type', SettlementLineType::Report) as $line) {
            if ($line->dailyReport !== null) {
                $this->checkReport($line, $line->dailyReport);
            }
        }

        $this->newLine();
        $this->{$this->failures === 0 ? 'info' : 'error'}($this->failures === 0 ? 'Everything checks out.' : "{$this->failures} difference(s) found.");

        return $this->failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkArithmetic(Settlement $settlement): void
    {
        $this->line('Settlement lines');
        $rows = [];
        $total = BigDecimal::zero();

        foreach ($settlement->lines as $line) {
            $expected = BigDecimal::of((string) $line->amount)->multipliedBy((string) $line->rate)->toScale(2, RoundingMode::HalfUp);
            $stored = BigDecimal::of((string) $line->amount_payout)->toScale(2, RoundingMode::HalfUp);
            $total = $total->plus($stored);
            $report = $line->dailyReport;
            $payoutDiff = $line->type === SettlementLineType::Report && $report ? BigDecimal::of((string) $line->amount)->minus((string) $report->net_payout)->toScale(2, RoundingMode::HalfUp) : null;

            $rows[] = [
                $line->type->label(),
                $report ? $report->report_date->toDateString().' · '.$report->merchantMid->mid : $line->description,
                $line->currency.' '.number_format((float) $line->amount, 2, '.', ''),
                (string) $line->rate,
                (string) $stored,
                $this->mark($expected->isEqualTo($stored)),
                $payoutDiff === null ? '—' : $this->mark($payoutDiff->abs()->isLessThanOrEqualTo('0.01')),
            ];
        }
        $this->table(['Line', 'What', 'Amount', 'Rate', 'Payout', 'amount×rate', 'line = report payout'], $rows);

        $stored = BigDecimal::of((string) $settlement->total_payout)->toScale(2, RoundingMode::HalfUp);
        $this->line("Sum of the lines {$total} · stored total {$stored} ".$this->mark($total->isEqualTo($stored)));
    }

    private function checkReport(SettlementLine $line, DailyReportTask $task): void
    {
        $mid = $task->merchantMid->load('merchant.cryptoProvider', 'bankProvider', 'gateProvider');
        $merchant = $mid->merchant;
        $ops = MerchantOperation::query()
            ->where('merchant_mid_id', $mid->id)
            ->whereBetween('report_date', [$task->period_from->toDateString(), $task->period_to->toDateString()])
            ->get();

        $bank = $ops->where('provider_id', $mid->bank_provider_id)->where('role', ProviderType::Bank);
        $gate = $mid->gate_provider_id ? $ops->where('provider_id', $mid->gate_provider_id)->where('role', ProviderType::Gate) : collect();
        $of = fn (Collection $set, OperationType $type) => $set->where('operation_type', $type);
        $sum = fn (Collection $set) => $set->reduce(fn (BigDecimal $c, MerchantOperation $o) => $c->plus(BigDecimal::of((string) $o->amount)->abs()), BigDecimal::zero())->toScale(2, RoundingMode::HalfUp);

        $sales = $of($bank, OperationType::Sale);
        $refunds = $of($bank, OperationType::Refund);
        $chargebacks = $of($bank, OperationType::Chargeback);
        $collabs = $of($bank, OperationType::Collab);
        $source = $mid->gate_provider_id ? $gate : $bank;
        $approved = $of($source, OperationType::Sale);
        $declines = $of($source, OperationType::Decline);

        $turnover = $sum($sales);
        $tariff = Tariff::forMid($mid);
        $round = fn (BigDecimal $v) => $v->toScale(2, RoundingMode::HalfUp);
        $percent = fn (Collection $set, Tariff $t) => $set->reduce(fn (BigDecimal $c, MerchantOperation $o) => $c->plus(BigDecimal::of((string) $o->amount)->abs()->multipliedBy($t->percentFor($o))->dividedBy(100, 8, RoundingMode::HalfUp)), BigDecimal::zero());

        $percentFee = $percent($sales, $tariff);
        $fixed = $tariff->fixed('success')->multipliedBy($approved->count())
            ->plus($tariff->fixed('decline')->multipliedBy($declines->count()))
            ->plus($tariff->fixed('refund')->multipliedBy($refunds->count()))
            ->plus($tariff->fixed('chargeback')->multipliedBy($chargebacks->count()))
            ->plus($collabs->isNotEmpty() ? $tariff->fixed('collab')->multipliedBy($collabs->count()) : BigDecimal::zero());
        $settled = BigDecimal::max(BigDecimal::zero(), $turnover->minus($sum($refunds))->minus($sum($chargebacks))->minus($sum($collabs)));
        $fxApplies = $mid->currency->value !== config('sterling.settlement_currency');
        $fxFee = $round($fxApplies ? $settled->multipliedBy($tariff->percent('settlement_fx'))->dividedBy(100, 8, RoundingMode::HalfUp) : BigDecimal::zero());
        $merchantFee = $round($percentFee->plus($fixed)->plus($fxFee));

        $cost = function (?Provider $provider, Collection $set) use ($percent, $round, $of): BigDecimal {
            if ($provider === null) {
                return BigDecimal::zero();
            }
            $t = Tariff::provider($provider);

            return $round($percent($of($set, OperationType::Sale), $t)
                ->plus($t->fixed('success')->multipliedBy($of($set, OperationType::Sale)->count()))
                ->plus($t->fixed('refund')->multipliedBy($of($set, OperationType::Refund)->count()))
                ->plus($t->fixed('chargeback')->multipliedBy($of($set, OperationType::Chargeback)->count()))
                ->plus($t->fixed('decline')->multipliedBy($of($set, OperationType::Decline)->count())));
        };
        $bankCost = $cost($mid->bankProvider, $bank);
        $gateCost = $mid->gateProvider ? $cost($mid->gateProvider, $gate) : BigDecimal::zero();
        $fxCost = $fxApplies && $mid->bankProvider ? $round($settled->multipliedBy(Tariff::provider($mid->bankProvider)->percent('settlement_fx'))->dividedBy(100, 8, RoundingMode::HalfUp)) : BigDecimal::zero();

        $reserveBase = $turnover->minus($sum($refunds))->minus($sum($chargebacks))->minus($sum($collabs))->minus($merchantFee);
        $reserve = $round(BigDecimal::max(BigDecimal::zero(), $reserveBase)->multipliedBy($merchant->rolling_reserve_percent ?? 0)->dividedBy(100, 8, RoundingMode::HalfUp));
        // The stored reserve may be lower: it is capped by the MID and merchant reserve limits. Followed from here as stored.
        $storedReserve = BigDecimal::of((string) $task->reserve_amount);
        $netVolume = $reserveBase->minus($storedReserve);
        $convertible = BigDecimal::max(BigDecimal::zero(), $netVolume);
        $conversion = $round($convertible->multipliedBy($tariff->percent('fiat_to_crypto'))->dividedBy(100, 8, RoundingMode::HalfUp));
        $cryptoCost = $merchant->cryptoProvider ? $round($convertible->multipliedBy(Tariff::provider($merchant->cryptoProvider)->percent('crypto'))->dividedBy(100, 8, RoundingMode::HalfUp)) : BigDecimal::zero();
        $payout = $netVolume->minus($conversion);
        $profit = $merchantFee->plus($conversion)->minus($bankCost->plus($gateCost)->plus($cryptoCost)->plus($fxCost));

        $summary = $task->summary_data ?? [];
        $this->newLine();
        $this->line("Report {$task->report_date->toDateString()} · MID {$mid->mid} · {$merchant->name} · {$mid->bankProvider?->name} ↔ {$mid->gateProvider?->name} · {$task->period_from->toDateString()} – {$task->period_to->toDateString()}");
        $this->line(sprintf('  operations: bank sales %d, refunds %d, chargebacks %d, collabs %d · gateway approved %d, declines %d', $sales->count(), $refunds->count(), $chargebacks->count(), $collabs->count(), $approved->count(), $declines->count()));

        $rows = [
            ['Sales (turnover)', $task->turnover, $turnover],
            ['Refunds', $task->refunds_amount, $sum($refunds)],
            ['Chargebacks', $task->chargebacks_amount, $sum($chargebacks)],
            ['Collabs', $task->collabs_amount, $sum($collabs)],
            ['Merchant fee, percent part', $summary['merchant_fee']['percent'] ?? null, $round($percentFee)],
            ['Merchant fee, fixed part', $summary['merchant_fee']['fixed'] ?? null, $round($fixed)],
            ['Merchant fee, total', $task->total_merchant_fee, $merchantFee],
            ['Acquirer cost', $summary['provider_cost']['bank'] ?? null, $bankCost],
            ['Gateway cost', $summary['provider_cost']['gate'] ?? null, $gateCost],
            ['Rolling reserve (before caps)', $task->reserve_amount, $reserve],
            ['Net volume', $task->net_volume, $round($netVolume)],
            ['Conversion fee', $task->conversion_fee, $conversion],
            ['Net payout', $task->net_payout, $round($payout)],
            ['Net profit', $task->net_profit, $round($profit)],
        ];

        $table = [];
        foreach ($rows as [$label, $stored, $recounted]) {
            $storedValue = $stored === null ? null : BigDecimal::of((string) $stored)->toScale(2, RoundingMode::HalfUp);
            $same = $storedValue !== null && $storedValue->minus($recounted)->abs()->isLessThanOrEqualTo('0.02');
            $table[] = [$label, (string) ($storedValue ?? '—'), (string) $recounted, $this->mark($same, $label === 'Rolling reserve (before caps)')];
        }
        $this->table(['', 'Stored', 'Recounted', ''], $table);
    }

    private function mark(bool $ok, bool $soft = false): string
    {
        if (! $ok && ! $soft) {
            $this->failures++;
        }

        return $ok ? '✓' : ($soft ? '≠ (a reserve limit may cap it)' : '✗');
    }
}

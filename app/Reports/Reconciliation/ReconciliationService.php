<?php

namespace App\Reports\Reconciliation;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Provider;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pairs the acquirer's operations of a MID with its gateway's operations,
 * whatever the two providers are. Rules come from `providers.matching`
 * (acquirer first, then gateway, then config defaults).
 *
 * Pairs are one-to-one: an operation that has a pair never takes part again.
 */
class ReconciliationService
{
    /** Hour shifts tried when a provider's file time zone is unreliable. */
    private const TIMEZONE_SHIFTS_HOURS = [1, -1, 2, -2, 3, -3];

    private const GATE_SLACK_DAYS = 5;

    /**
     * @return int number of new pairs
     */
    public function reconcile(MerchantMid $mid, DateTimeInterface|string $from, DateTimeInterface|string $to): int
    {
        if ($mid->bank_provider_id === null || $mid->gate_provider_id === null) {
            return 0;
        }

        $mid->loadMissing('bankProvider', 'gateProvider');
        $rules = $this->rulesFor($mid->bankProvider, $mid->gateProvider);

        $from = CarbonImmutable::parse($from);
        $to = CarbonImmutable::parse($to);

        // Clearing lags the payment by a few days, so the gateway side is
        // searched in a wider range than the acquirer's report period.
        $bank = $this->unmatched($mid, ProviderType::Bank, $from->toDateString(), $to->toDateString());
        $gate = $this->unmatched($mid, ProviderType::Gate, $from->subDays(self::GATE_SLACK_DAYS)->toDateString(), $to->addDays(self::GATE_SLACK_DAYS)->toDateString());
        if ($bank->isEmpty() || $gate->isEmpty()) {
            return 0;
        }

        $pairs = [];
        $taken = [];

        foreach ($rules['keys'] as $key) {
            $index = [];
            foreach ($gate as $op) {
                if (! isset($taken[$op->id]) && ($k = $this->key($op, $key)) !== null) {
                    $index[$k][] = $op;
                }
            }

            foreach ($bank as $op) {
                if (isset($pairs[$op->id]) || ($k = $this->key($op, $key)) === null || empty($index[$k])) {
                    continue;
                }

                $candidates = array_values(array_filter($index[$k], fn (MerchantOperation $g) => ! isset($taken[$g->id])));
                $match = $this->pick($op, $candidates, $rules);
                if ($match !== null) {
                    $pairs[$op->id] = [$op, $match];
                    $taken[$match->id] = true;
                }
            }
        }

        DB::transaction(function () use ($pairs) {
            foreach ($pairs as [$bankOp, $gateOp]) {
                $bankOp->update(['matched_operation_id' => $gateOp->id, 'sp_id' => $gateOp->payment_id]);
                $gateOp->update(['matched_operation_id' => $bankOp->id, 'sp_id' => $bankOp->payment_id]);
            }
        });

        return count($pairs);
    }

    /**
     * @return array{keys: list<list<string>>, window_minutes: int, try_timezone_shift: bool, tie_breakers: list<string>}
     */
    public function rulesFor(?Provider $bank, ?Provider $gate): array
    {
        if ($bank?->matching) {
            return $bank->matchingRules();
        }

        return $gate?->matchingRules() ?? $bank?->matchingRules() ?? config('sterling.matching');
    }

    /**
     * @return Collection<int, MerchantOperation>
     */
    private function unmatched(MerchantMid $mid, ProviderType $role, string $from, string $to): Collection
    {
        return MerchantOperation::query()
            ->where('merchant_mid_id', $mid->id)
            ->where('role', $role)
            ->whereNull('matched_operation_id')
            // Declines never reach clearing, so they have nothing to pair with.
            ->where('operation_type', '!=', OperationType::Decline)
            ->whereBetween('report_date', [$from, $to])
            ->orderBy('transaction_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<string>  $fields
     */
    private function key(MerchantOperation $op, array $fields): ?string
    {
        $parts = [$op->operation_type->value];
        foreach ($fields as $field) {
            $value = match ($field) {
                'amount' => number_format(abs((float) $op->amount), 2, '.', ''),
                'currency' => $op->currency,
                'email' => $op->customer_email,
                default => $op->getAttribute($field),
            };
            if ($value === null || $value === '') {
                return null;
            }
            $parts[] = strtolower((string) $value);
        }

        return implode('|', $parts);
    }

    /**
     * @param  list<MerchantOperation>  $candidates
     * @param  array{window_minutes: int, try_timezone_shift: bool, tie_breakers: list<string>}  $rules
     */
    private function pick(MerchantOperation $op, array $candidates, array $rules): ?MerchantOperation
    {
        $window = $rules['window_minutes'] * 60;
        $shifts = $rules['try_timezone_shift'] ? [0, ...self::TIMEZONE_SHIFTS_HOURS] : [0];

        foreach ($shifts as $shift) {
            $inWindow = array_values(array_filter(
                $candidates,
                fn (MerchantOperation $g) => $this->distance($op, $g, $shift) <= $window,
            ));

            if ($inWindow !== []) {
                return $this->breakTie($op, $inWindow, $rules['tie_breakers'], $shift);
            }
        }

        return null;
    }

    /**
     * Seconds between the two operations after shifting the gateway time;
     * 0 when either side has no time (the key alone decides then).
     */
    private function distance(MerchantOperation $bank, MerchantOperation $gate, int $shiftHours): int
    {
        if ($bank->transaction_at === null || $gate->transaction_at === null) {
            return $shiftHours === 0 ? 0 : PHP_INT_MAX;
        }

        return (int) abs($bank->transaction_at->getTimestamp() - ($gate->transaction_at->getTimestamp() + $shiftHours * 3600));
    }

    /**
     * @param  list<MerchantOperation>  $candidates
     * @param  list<string>  $tieBreakers
     */
    private function breakTie(MerchantOperation $op, array $candidates, array $tieBreakers, int $shift): MerchantOperation
    {
        foreach ($tieBreakers as $breaker) {
            if (count($candidates) === 1) {
                break;
            }

            if ($breaker === 'email' && $op->customer_email !== null) {
                $same = array_values(array_filter($candidates, fn (MerchantOperation $g) => $g->customer_email === $op->customer_email));
                $candidates = $same ?: $candidates;
            }

            if ($breaker === 'closest_time') {
                usort($candidates, fn ($a, $b) => $this->distance($op, $a, $shift) <=> $this->distance($op, $b, $shift) ?: $a->id <=> $b->id);
                $candidates = [$candidates[0]];
            }
        }

        return $candidates[0];
    }
}

<?php

namespace App\Reports\Reconciliation;

use App\Enums\MidStatus;
use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Reports\Ingestion\ReportIngestionService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pairs the acquirer's operations of a MID with its gateway's operations,
 * whatever the two providers are. Rules come from `providers.matching`
 * (acquirer first, then gateway, then config defaults).
 *
 * Pairs are one-to-one: an operation that has a pair never takes part again.
 *
 * One gateway account can feed several terminals (MIDs of different banks).
 * Its rows are first booked to one of them; when a bank operation of another
 * MID pairs with such a row, the row moves to that MID.
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
        $declines = (bool) $rules['include_declines'];
        $bank = $this->unmatched([$mid->id], ProviderType::Bank, $from->toDateString(), $to->toDateString(), $declines);
        $gate = $this->unmatched([$mid->id, ...$this->sharingGate($mid)], ProviderType::Gate, $from->subDays(self::GATE_SLACK_DAYS)->toDateString(), $to->addDays(self::GATE_SLACK_DAYS)->toDateString(), $declines);
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

        DB::transaction(function () use ($pairs, $mid) {
            foreach ($pairs as [$bankOp, $gateOp]) {
                $bankOp->update(['matched_operation_id' => $gateOp->id, 'sp_id' => $gateOp->payment_id]);
                $gateOp->update(['matched_operation_id' => $bankOp->id, 'sp_id' => $bankOp->payment_id]);

                // The gateway row was booked to a sibling terminal: it belongs to this MID.
                if ((int) $gateOp->merchant_mid_id !== $mid->id) {
                    $gateOp->update(['merchant_id' => $mid->merchant_id, 'merchant_mid_id' => $mid->id]);
                    if ($bankOp->report_date !== null) {
                        app(ReportIngestionService::class)->ensureReport($mid->gateProvider, $mid, CarbonImmutable::parse($bankOp->report_date));
                    }
                }
            }
        });

        return count($pairs);
    }

    /**
     * Unpaired operations of the other side the operation could be paired with by hand:
     * the same terminal (or one sharing its gateway account), same type and currency,
     * within the clearing window; same amount unless `$anyAmount`. Closest in time first.
     *
     * @return Collection<int, MerchantOperation>
     */
    public function candidates(MerchantOperation $op, int $limit = 10, bool $anyAmount = false): Collection
    {
        $mid = $op->merchantMid;
        if ($op->matched_operation_id !== null || $mid === null || $mid->bank_provider_id === null || $mid->gate_provider_id === null) {
            return collect();
        }

        $other = $op->role === ProviderType::Bank ? ProviderType::Gate : ProviderType::Bank;
        $at = $op->transaction_at;

        return MerchantOperation::query()
            ->with('provider:id,name')
            ->whereIn('merchant_mid_id', [$mid->id, ...$this->sharingGate($mid)])
            ->where('role', $other)
            ->whereNull('matched_operation_id')
            ->where('operation_type', $op->operation_type)
            ->where('currency', $op->currency)
            ->when(! $anyAmount, fn ($q) => $q->where('amount', $op->amount))
            ->when($at !== null, fn ($q) => $q->whereBetween('transaction_at', [$at->copy()->subDays(self::GATE_SLACK_DAYS), $at->copy()->addDays(self::GATE_SLACK_DAYS)]))
            ->limit(200)
            ->get()
            ->sortBy(fn (MerchantOperation $c) => $at !== null && $c->transaction_at !== null ? abs($at->getTimestamp() - $c->transaction_at->getTimestamp()) : PHP_INT_MAX)
            ->take($limit)
            ->values();
    }

    /**
     * Pairs two operations by hand, in the same way reconciliation does:
     * ids on both sides and the gateway row on the acquirer row's terminal.
     */
    public function pair(MerchantOperation $a, MerchantOperation $b): void
    {
        if ($a->matched_operation_id !== null || $b->matched_operation_id !== null) {
            throw ValidationException::withMessages(['pair' => 'One of the operations already has a pair; unpair it first.']);
        }
        if ($a->role === $b->role) {
            throw ValidationException::withMessages(['pair' => 'A pair is one acquirer row and one gateway row.']);
        }

        [$bank, $gate] = $a->role === ProviderType::Bank ? [$a, $b] : [$b, $a];
        $mid = $bank->merchantMid;
        if ($mid === null || ! in_array($gate->merchant_mid_id, [$mid->id, ...$this->sharingGate($mid)], true)) {
            throw ValidationException::withMessages(['pair' => 'The two operations are not on the same terminal or on terminals sharing a gateway account.']);
        }

        DB::transaction(function () use ($bank, $gate, $mid) {
            $bank->update(['matched_operation_id' => $gate->id, 'sp_id' => $gate->payment_id]);
            $gate->update(['matched_operation_id' => $bank->id, 'sp_id' => $bank->payment_id]);

            if ((int) $gate->merchant_mid_id !== $mid->id) {
                $gate->update(['merchant_id' => $mid->merchant_id, 'merchant_mid_id' => $mid->id]);
                if ($bank->report_date !== null) {
                    app(ReportIngestionService::class)->ensureReport($mid->gateProvider, $mid, CarbonImmutable::parse($bank->report_date));
                }
            }
        });
    }

    public function unpair(MerchantOperation $op): void
    {
        if ($op->matched_operation_id === null) {
            return;
        }

        DB::transaction(function () use ($op) {
            MerchantOperation::query()->whereKey($op->matched_operation_id)->update(['matched_operation_id' => null, 'sp_id' => null]);
            $op->update(['matched_operation_id' => null, 'sp_id' => null]);
        });
    }

    /**
     * @return array{keys: list<list<string>>, window_minutes: int, try_timezone_shift: bool, tie_breakers: list<string>, include_declines: bool}
     */
    public function rulesFor(?Provider $bank, ?Provider $gate): array
    {
        if ($bank?->matching) {
            return $bank->matchingRules();
        }

        return $gate?->matchingRules() ?? $bank?->matchingRules() ?? config('sterling.matching');
    }

    /**
     * Other MIDs fed by one of this MID's gateway accounts.
     *
     * @return list<int>
     */
    private function sharingGate(MerchantMid $mid): array
    {
        $accounts = $mid->gateAccounts();
        if ($accounts === []) {
            return [];
        }

        return MerchantMid::query()
            ->where('gate_provider_id', $mid->gate_provider_id)
            ->whereKeyNot($mid->id)
            ->where('status', '!=', MidStatus::Inactive->value)
            ->get()
            ->filter(fn (MerchantMid $other) => array_intersect($accounts, $other->gateAccounts()) !== [])
            ->pluck('id')
            ->all();
    }

    /**
     * @param  list<int>  $midIds
     * @return Collection<int, MerchantOperation>
     */
    private function unmatched(array $midIds, ProviderType $role, string $from, string $to, bool $withDeclines = false): Collection
    {
        return MerchantOperation::query()
            ->whereIn('merchant_mid_id', $midIds)
            ->where('role', $role)
            ->whereNull('matched_operation_id')
            // Declines never reach clearing, so by default they have nothing to pair with.
            ->when(! $withDeclines, fn ($q) => $q->where('operation_type', '!=', OperationType::Decline))
            // Collaboration cases are acquirer notices, not payments: nothing to pair them with.
            ->where('operation_type', '!=', OperationType::Collab)
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

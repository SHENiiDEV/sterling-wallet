<?php

namespace App\Reports\Reconciliation;

use App\Enums\MidStatus;
use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\DailyReportTask;
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
 *
 * The other way round, one bank terminal can be given to several merchants (MID records sharing a terminal). The
 * bank's row does not say whose it is, the gateway's payment does (its commerce account): the bank row waits as
 * `unassigned` and moves to the MID of the gateway payment it pairs with, by card, amount and time.
 */
class ReconciliationService
{
    /** Hour shifts tried when a provider's file time zone is unreliable. */
    private const TIMEZONE_SHIFTS_HOURS = [1, -1, 2, -2, 3, -3, 4, -4];

    private const GATE_SLACK_DAYS = 5;

    /** Leftover rows with the same card and amount are paired when no more than this far apart. */
    private const LEFTOVER_MAX_GAP_SECONDS = 86400;

    /**
     * @param  bool  $rebuildOwn  build again the report of this MID when a row moved to it (a caller that is calculating it passes false)
     * @return int number of new pairs
     */
    public function reconcile(MerchantMid $mid, DateTimeInterface|string $from, DateTimeInterface|string $to, bool $rebuildOwn = true): int
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
        // A shared terminal pairs as one: the rows of every merchant on it against the payments of every one of them.
        $mates = $mid->terminalMates()->get();
        $shared = $mates->count() > 1;
        $bankIds = $shared ? $mates->pluck('id')->all() : [$mid->id];
        $gateIds = collect($bankIds)->flatMap(fn (int $id) => [$id, ...$this->sharingGate($mates->firstWhere('id', $id) ?? $mid)])->unique()->values()->all();
        $bank = $this->unmatched($bankIds, ProviderType::Bank, $from->toDateString(), $to->toDateString(), $declines);
        $gate = $this->unmatched($gateIds, ProviderType::Gate, $from->subDays(self::GATE_SLACK_DAYS)->toDateString(), $to->addDays(self::GATE_SLACK_DAYS)->toDateString(), $declines);
        if ($bank->isEmpty() || $gate->isEmpty()) {
            if ($shared) {
                $this->rebuild($this->homeRefunds($mates, $bankIds, $from, $to), $mid, $rebuildOwn);
            }

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

        // What is left: the same card and amount on both sides, as many rows on one side as on the other, but hours apart
        // (a payment retried later, a clearing time far from the authorisation). Paired in time order, up to a day apart.
        if ($rules['pair_leftovers'] ?? false) {
            [$pairs, $taken] = $this->pairLeftovers($bank, $gate, $pairs, $taken);
        }

        $moved = [];
        DB::transaction(function () use ($pairs, $mid, $mates, $shared, &$moved) {
            foreach ($pairs as [$bankOp, $gateOp]) {
                // An acquirer file without a region (Madfin's portal export) takes it from the gateway's issuer country;
                // unknown is priced as non-EU, so the fee would otherwise be too high for EU cards.
                $bankOp->update(['matched_operation_id' => $gateOp->id, 'sp_id' => $gateOp->payment_id, ...($bankOp->region === null && $gateOp->region !== null ? ['region' => $gateOp->region] : [])]);
                $gateOp->update(['matched_operation_id' => $bankOp->id, 'sp_id' => $bankOp->payment_id]);

                // A shared terminal: the gateway payment knows its merchant, so the bank row goes to it.
                $owner = $shared ? $mates->firstWhere('id', (int) $gateOp->merchant_mid_id) : null;
                if ($owner !== null) {
                    if ($bankOp->unassigned || (int) $bankOp->merchant_mid_id !== $owner->id) {
                        $bankOp->update(['merchant_id' => $owner->merchant_id, 'merchant_mid_id' => $owner->id, 'unassigned' => false]);
                        if ($bankOp->report_date !== null) {
                            $task = app(ReportIngestionService::class)->ensureReport($owner->bankProvider ?? $mid->bankProvider, $owner, CarbonImmutable::parse($bankOp->report_date));
                            $moved[$task->id] = $task;
                        }
                    }

                    continue;
                }

                // The gateway row was booked to a sibling terminal: it belongs to this MID.
                if ((int) $gateOp->merchant_mid_id !== $mid->id) {
                    $gateOp->update(['merchant_id' => $mid->merchant_id, 'merchant_mid_id' => $mid->id]);
                    if ($bankOp->report_date !== null) {
                        app(ReportIngestionService::class)->ensureReport($mid->gateProvider, $mid, CarbonImmutable::parse($bankOp->report_date));
                    }
                }
            }
        });

        if ($shared) {
            $moved += $this->homeRefunds($mates, $bankIds, $from, $to);
        }
        $this->rebuild($moved, $mid, $rebuildOwn);

        return count($pairs);
    }

    /**
     * The reports a row moved into have to be built again to carry it (a paid one is left as it is: it is found by
     * reports:late-operations).
     *
     * @param  array<int, DailyReportTask>  $tasks
     */
    private function rebuild(array $tasks, MerchantMid $mid, bool $rebuildOwn): void
    {
        foreach ($tasks as $task) {
            if (! $rebuildOwn && (int) $task->merchant_mid_id === $mid->id) {
                continue;
            }
            app(ReportIngestionService::class)->advance($task);
        }
    }

    /**
     * A refund of a shared terminal cannot pair with a payment: it belongs to the merchant whose sale it gives back,
     * the latest sale of the same card and amount before it. One that finds no sale stays unassigned.
     *
     * @param  Collection<int, MerchantMid>  $mates
     * @param  list<int>  $ids
     * @return array<int, DailyReportTask> the reports the refunds moved into
     */
    private function homeRefunds(Collection $mates, array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $homed = [];
        $refunds = MerchantOperation::query()->whereIn('merchant_mid_id', $ids)->where('role', ProviderType::Bank)
            ->where('operation_type', OperationType::Refund)->where('unassigned', true)
            ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()])->get();

        foreach ($refunds as $refund) {
            if ($refund->card_bin === null || $refund->card_last4 === null) {
                continue;
            }
            $sale = MerchantOperation::query()
                ->whereIn('merchant_mid_id', $ids)->where('role', ProviderType::Bank)->where('operation_type', OperationType::Sale)->where('unassigned', false)
                ->where('card_bin', $refund->card_bin)->where('card_last4', $refund->card_last4)->where('amount', abs((float) $refund->getRawOriginal('amount')))
                ->when($refund->transaction_at, fn ($q, $at) => $q->where('transaction_at', '<=', $at))
                ->orderByDesc('transaction_at')->orderByDesc('id')->first();
            $owner = $sale ? $mates->firstWhere('id', (int) $sale->merchant_mid_id) : null;
            if ($owner === null) {
                continue;
            }

            $refund->update(['merchant_id' => $owner->merchant_id, 'merchant_mid_id' => $owner->id, 'unassigned' => false]);
            if ($refund->report_date !== null) {
                $task = app(ReportIngestionService::class)->ensureReport($owner->bankProvider, $owner, CarbonImmutable::parse($refund->report_date));
                $homed[$task->id] = $task;
            }
        }

        return $homed;
    }

    /**
     * @param  Collection<int, MerchantOperation>  $bank
     * @param  Collection<int, MerchantOperation>  $gate
     * @param  array<int, array{0: MerchantOperation, 1: MerchantOperation}>  $pairs
     * @param  array<int, bool>  $taken
     * @return array{0: array<int, array{0: MerchantOperation, 1: MerchantOperation}>, 1: array<int, bool>}
     */
    private function pairLeftovers(Collection $bank, Collection $gate, array $pairs, array $taken): array
    {
        $fields = ['card_bin', 'card_last4', 'amount', 'currency'];
        $group = function (Collection $ops, callable $left) use ($fields) {
            $groups = [];
            foreach ($ops->filter($left) as $op) {
                if (($k = $this->key($op, $fields)) !== null && $op->transaction_at !== null) {
                    $groups[$k][] = $op;
                }
            }
            foreach ($groups as &$rows) {
                usort($rows, fn (MerchantOperation $a, MerchantOperation $b) => $a->transaction_at->getTimestamp() <=> $b->transaction_at->getTimestamp() ?: $a->id <=> $b->id);
            }

            return $groups;
        };

        $bankGroups = $group($bank, fn (MerchantOperation $op) => ! isset($pairs[$op->id]));
        $gateGroups = $group($gate, fn (MerchantOperation $op) => ! isset($taken[$op->id]));

        foreach ($bankGroups as $k => $bankRows) {
            $gateRows = $gateGroups[$k] ?? [];
            if (count($gateRows) !== count($bankRows)) {
                continue;
            }

            $close = true;
            foreach ($bankRows as $i => $op) {
                $close = $close && $this->nearestGap($op, $gateRows[$i]) <= self::LEFTOVER_MAX_GAP_SECONDS;
            }
            if (! $close) {
                continue;
            }

            foreach ($bankRows as $i => $op) {
                $pairs[$op->id] = [$op, $gateRows[$i]];
                $taken[$gateRows[$i]->id] = true;
            }
        }

        return [$pairs, $taken];
    }

    /**
     * Seconds apart after the whole-hour shift that brings the two closest.
     */
    private function nearestGap(MerchantOperation $bank, MerchantOperation $gate): int
    {
        return min(array_map(fn (int $shift) => $this->distance($bank, $gate, $shift), [0, ...self::TIMEZONE_SHIFTS_HOURS]));
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
        $mates = $mid?->terminalMates()->get() ?? collect();
        if ($mid === null || ! in_array($gate->merchant_mid_id, [$mid->id, ...$this->sharingGate($mid), ...$mates->pluck('id')->all()], true)) {
            throw ValidationException::withMessages(['pair' => 'The two operations are not on the same terminal or on terminals sharing a gateway account.']);
        }

        $moved = [];
        DB::transaction(function () use ($bank, $gate, $mid, $mates, &$moved) {
            $bank->update(['matched_operation_id' => $gate->id, 'sp_id' => $gate->payment_id]);
            $gate->update(['matched_operation_id' => $bank->id, 'sp_id' => $bank->payment_id]);

            // A shared terminal: the bank row goes to the merchant of the gateway payment.
            if ($mates->count() > 1 && ($owner = $mates->firstWhere('id', (int) $gate->merchant_mid_id)) !== null) {
                $bank->update(['merchant_id' => $owner->merchant_id, 'merchant_mid_id' => $owner->id, 'unassigned' => false]);
                if ($bank->report_date !== null) {
                    $task = app(ReportIngestionService::class)->ensureReport($owner->bankProvider, $owner, CarbonImmutable::parse($bank->report_date));
                    $moved[$task->id] = $task;
                }

                return;
            }

            if ((int) $gate->merchant_mid_id !== $mid->id) {
                $gate->update(['merchant_id' => $mid->merchant_id, 'merchant_mid_id' => $mid->id]);
                if ($bank->report_date !== null) {
                    app(ReportIngestionService::class)->ensureReport($mid->gateProvider, $mid, CarbonImmutable::parse($bank->report_date));
                }
            }
        });

        $this->rebuild($moved, $mid, true);
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
     * @return array{keys: list<list<string>>, window_minutes: int, try_timezone_shift: bool, tie_breakers: list<string>, include_declines: bool, pair_leftovers?: bool}
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
            // So are the chargebacks the acquirer lists: they are about a payment already paid, not a new one.
            ->where('operation_type', '!=', OperationType::Chargeback)
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

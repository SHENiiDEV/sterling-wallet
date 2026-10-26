<?php

namespace App\Reports;

use App\Enums\MidStatus;
use App\Enums\ProviderType;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Provider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * Successful gateway volume per terminal (MID) for a day, a month or any
 * range, against the terminal's daily norm and monthly processing limit.
 * The gateway is the system that sees every attempt, so only its rows count.
 */
class TerminalVolumes
{
    /**
     * One row per terminal that has a gateway, optionally only of some merchants (the merchant portal).
     *
     * @param  list<int>|null  $merchantIds
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(CarbonImmutable $from, CarbonImmutable $to, ?int $midId = null, ?array $merchantIds = null): Collection
    {
        $stats = $this->withinDays(MerchantOperation::query()->toBase()->where('role', ProviderType::Gate->value), $from, $to)
            ->whereNotNull('merchant_mid_id')
            ->when($midId, fn ($q, int $id) => $q->where('merchant_mid_id', $id))
            ->when($merchantIds !== null, fn ($q) => $q->whereIn('merchant_id', $merchantIds))
            ->selectRaw('merchant_mid_id, '.$this->aggregates())
            ->groupBy('merchant_mid_id')
            ->get()
            ->keyBy('merchant_mid_id');

        $days = (int) $from->diffInDays($to) + 1;

        return MerchantMid::query()
            ->with(['merchant:id,public_id,name', 'bankProvider:id,name', 'gateProvider:id,name'])
            ->whereNotNull('gate_provider_id')
            ->when($midId, fn ($q, int $id) => $q->whereKey($id))
            ->when($merchantIds !== null, fn ($q) => $q->whereIn('merchant_id', $merchantIds))
            ->orderBy('mid')
            ->get()
            ->map(function (MerchantMid $mid) use ($stats, $days, $from) {
                $s = $stats->get($mid->id);
                $volume = round((float) ($s->volume ?? 0), 2);
                $approved = (int) ($s->approved ?? 0);
                $declined = (int) ($s->declined ?? 0);
                $limit = $this->totalLimit($mid);
                $norm = $this->dailyNorm($mid, $from);

                return [
                    'id' => $mid->id,
                    'mid' => $mid->mid,
                    'currency' => $mid->currency->value,
                    'status' => $mid->status->value,
                    'merchant' => ['public_id' => $mid->merchant->public_id, 'name' => $mid->merchant->name],
                    'pair' => ($mid->bankProvider?->name ?? '—').' ↔ '.($mid->gateProvider?->name ?? '—'),
                    'volume' => $volume,
                    'approved' => $approved,
                    'declined' => $declined,
                    'approval_rate' => $approved + $declined > 0 ? round($approved / ($approved + $declined) * 100, 1) : null,
                    'limit' => $limit,
                    'used_percent' => $limit ? round($volume / $limit * 100, 1) : null,
                    // The norm for the whole range: a day's norm times its days.
                    'norm' => $norm !== null ? round($norm['amount'] * $days, 2) : null,
                    'norm_derived' => $norm['derived'] ?? false,
                    'norm_percent' => $norm !== null && $norm['amount'] > 0 ? round($volume / ($norm['amount'] * $days) * 100, 1) : null,
                    'shortfall' => $norm !== null ? round(max(0, $norm['amount'] * $days - $volume), 2) : null,
                ];
            })
            // Terminals that did something or are live; dormant ones stay out of the way.
            ->filter(fn (array $r) => $r['volume'] > 0 || $r['declined'] > 0 || $r['status'] === MidStatus::Active->value)
            ->sortByDesc('volume')
            ->values();
    }

    /**
     * Day by day figures of one terminal.
     *
     * @return list<array<string, mixed>>
     */
    public function series(int $midId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $mid = MerchantMid::query()->with('gateProvider:id,timezone')->find($midId);
        $zone = $this->zone($mid?->gateProvider?->timezone);

        // Each day is a window of the gateway's local day, so a Fri–Sun bundle report still lands on its own days.
        $cases = [];
        $bindings = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            [$start, $end] = $this->window($day, $day, $zone);
            $cases[] = 'when transaction_at >= ? and transaction_at < ? then ?';
            array_push($bindings, $start, $end, $day->toDateString());
        }

        $perDay = MerchantOperation::query()->toBase()
            ->where('role', ProviderType::Gate->value)
            ->where('merchant_mid_id', $midId)
            ->tap(fn ($q) => $this->betweenDays($q, $from, $to, $zone))
            ->selectRaw('case '.implode(' ', $cases).' else report_date end as bucket, '.$this->aggregates(), $bindings)
            ->groupBy('bucket')
            ->get()
            ->keyBy(fn ($r) => CarbonImmutable::parse($r->bucket)->toDateString());

        $limit = $mid ? $this->totalLimit($mid) : null;

        $running = 0.0;
        $days = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $d = $perDay->get($day->toDateString());
            $volume = round((float) ($d->volume ?? 0), 2);
            $running += $volume;
            $norm = $mid ? $this->dailyNorm($mid, $day) : null;

            $days[] = [
                'date' => $day->toDateString(),
                'approved' => (int) ($d->approved ?? 0),
                'declined' => (int) ($d->declined ?? 0),
                'volume' => $volume,
                'norm' => $norm['amount'] ?? null,
                'norm_percent' => $norm !== null && $norm['amount'] > 0 ? round($volume / $norm['amount'] * 100, 1) : null,
                'cumulative' => round($running, 2),
                'cumulative_percent' => $limit ? round($running / $limit * 100, 1) : null,
            ];
        }

        return $days;
    }

    /**
     * The daily norm: set on the terminal, else its monthly limit spread over the month.
     *
     * @return array{amount: float, derived: bool}|null
     */
    private function dailyNorm(MerchantMid $mid, CarbonImmutable $day): ?array
    {
        if ($mid->daily_norm !== null && (float) $mid->daily_norm > 0) {
            return ['amount' => (float) $mid->daily_norm, 'derived' => false];
        }

        $limit = $this->totalLimit($mid);

        return $limit !== null && $limit > 0 ? ['amount' => round($limit / $day->daysInMonth, 2), 'derived' => true] : null;
    }

    /**
     * Down to the configured step (12 890 → 12 500); an amount below one step goes down to whole units.
     */
    private function roundDown(float $amount): int
    {
        $step = max(1, (int) config('sterling.volumes.round_step'));
        $rounded = (int) (floor($amount / $step) * $step);

        return $rounded > 0 ? $rounded : (int) floor($amount);
    }

    /**
     * What the terminal may take in a month: the per-scheme limits added up
     * when it has them, else its single processing limit.
     */
    private function totalLimit(MerchantMid $mid): ?float
    {
        $schemes = array_filter([$mid->processing_limit_visa, $mid->processing_limit_mastercard], fn ($v) => $v !== null);
        if ($schemes !== []) {
            return round(array_sum(array_map('floatval', $schemes)), 2);
        }

        return $mid->processing_limit !== null ? (float) $mid->processing_limit : null;
    }

    /**
     * What to give each terminal per day to fill it by the end of the month.
     * Per limit ("bucket"): a terminal with limits per scheme gets one bucket
     * per scheme, otherwise one for everything. needed = (limit - processed up
     * to yesterday) / the days after today. Today is not counted: its report
     * only arrives tomorrow, so neither its volume nor the day itself is in the plan.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function plan(CarbonImmutable $asOf): Collection
    {
        $from = $asOf->startOfMonth();
        $daysInMonth = $asOf->daysInMonth;
        $daysLeft = $daysInMonth - $asOf->day;
        $completedDays = $asOf->day - 1;
        $through = $asOf->subDay();

        // Successful volume so far this month per terminal and card scheme.
        $processed = $this->withinDays(MerchantOperation::query()->toBase()->where('role', ProviderType::Gate->value)->where('operation_type', 'sale'), $from, $through)
            ->whereNotNull('merchant_mid_id')
            ->selectRaw('merchant_mid_id, ips, sum(amount) as volume, count(*) as approved')
            ->groupBy('merchant_mid_id', 'ips')
            ->get()
            ->groupBy('merchant_mid_id');

        return MerchantMid::query()
            ->with(['merchant:id,public_id,name', 'bankProvider:id,name', 'gateProvider:id,name'])
            ->whereNotNull('gate_provider_id')
            ->where('status', MidStatus::Active)
            ->orderBy('mid')
            ->get()
            ->map(function (MerchantMid $mid) use ($processed, $daysLeft, $completedDays, $daysInMonth) {
                $own = $processed->get($mid->id, collect());
                $sum = fn (?string $scheme) => (float) $own->filter(fn ($r) => $scheme === null || $r->ips === $scheme)->sum('volume');
                $count = fn (?string $scheme) => (int) $own->filter(fn ($r) => $scheme === null || $r->ips === $scheme)->sum('approved');

                $limits = $mid->processing_limit_visa !== null || $mid->processing_limit_mastercard !== null
                    ? array_filter(['visa' => $mid->processing_limit_visa, 'mastercard' => $mid->processing_limit_mastercard], fn ($v) => $v !== null)
                    : ($mid->processing_limit !== null ? ['all' => $mid->processing_limit] : []);

                $buckets = [];
                foreach ($limits as $scheme => $limit) {
                    $limit = (float) $limit;
                    $done = round($sum($scheme === 'all' ? null : $scheme), 2);
                    $left = round(max(0, $limit - $done), 2);
                    $needed = $daysLeft > 0 ? round($left / $daysLeft, 2) : null;
                    $evenPace = round($limit / $daysInMonth, 2);

                    $buckets[] = [
                        'scheme' => $scheme,
                        'label' => ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'all' => 'All cards'][$scheme],
                        'limit' => $limit,
                        'processed' => $done,
                        'approved' => $count($scheme === 'all' ? null : $scheme),
                        'used_percent' => $limit > 0 ? round($done / $limit * 100, 1) : null,
                        'left' => $left,
                        'needed_per_day' => $needed,
                        // What to type into the PSP filter: rounded down to the step, never above the need.
                        'needed_rounded' => $needed !== null ? $this->roundDown($needed) : null,
                        'even_pace' => $evenPace,
                        // Completed days at the normal pace versus what was really processed.
                        'behind' => round(max(0, $evenPace * $completedDays - $done), 2),
                        'filled' => $left <= 0,
                    ];
                }

                return [
                    'id' => $mid->id,
                    'mid' => $mid->mid,
                    'currency' => $mid->currency->value,
                    'merchant' => ['public_id' => $mid->merchant->public_id, 'name' => $mid->merchant->name],
                    'pair' => ($mid->bankProvider?->name ?? '—').' ↔ '.($mid->gateProvider?->name ?? '—'),
                    'buckets' => $buckets,
                ];
            })
            ->filter(fn (array $r) => $r['buckets'] !== [])
            ->values();
    }

    /**
     * Restricts the rows to days `$from`..`$to`, each gateway's days counted in its own timezone.
     * Rows without a time fall back to their report date.
     */
    private function withinDays(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $zones = Provider::query()->ofType(ProviderType::Gate)->get(['id', 'timezone'])
            ->groupBy(fn (Provider $p) => $this->zone($p->timezone));

        return $query->where(function (Builder $q) use ($zones, $from, $to) {
            foreach ($zones as $zone => $providers) {
                [$start, $end] = $this->window($from, $to, $zone);
                $q->orWhere(fn (Builder $z) => $z->whereIn('provider_id', $providers->pluck('id'))->where('transaction_at', '>=', $start)->where('transaction_at', '<', $end));
            }
            $q->orWhere(fn (Builder $z) => $z->whereNull('transaction_at')->whereBetween('report_date', [$from->toDateString(), $to->toDateString()]));
        });
    }

    /**
     * The same restriction for rows of one gateway timezone.
     */
    private function betweenDays(Builder $query, CarbonImmutable $from, CarbonImmutable $to, string $zone): Builder
    {
        [$start, $end] = $this->window($from, $to, $zone);

        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $z) => $z->where('transaction_at', '>=', $start)->where('transaction_at', '<', $end))
            ->orWhere(fn (Builder $z) => $z->whereNull('transaction_at')->whereBetween('report_date', [$from->toDateString(), $to->toDateString()])));
    }

    /**
     * UTC bounds [start, end) of the local days `$from`..`$to` in `$zone`.
     *
     * @return array{0: string, 1: string}
     */
    private function window(CarbonImmutable $from, CarbonImmutable $to, string $zone): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from->toDateString(), $zone)->utc();
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to->toDateString(), $zone)->addDay()->utc();

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    private function zone(?string $timezone): string
    {
        return filled($timezone) ? $timezone : config('sterling.timezone');
    }

    private function aggregates(): string
    {
        return "sum(case when operation_type = 'sale' then 1 else 0 end) as approved, "
            ."sum(case when operation_type = 'sale' then amount else 0 end) as volume, "
            ."sum(case when operation_type = 'decline' then 1 else 0 end) as declined";
    }
}

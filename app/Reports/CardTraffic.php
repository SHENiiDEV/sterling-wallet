<?php

namespace App\Reports;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\Client;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Internal control of the traffic a merchant (or a single MID) brings.
 *
 * New cards: how many of the cards paying on a day were never seen there before. Healthy, trusted traffic has repeat
 * customers, so the share of new cards stays below a limit (20% by default). A card is the BIN plus the last four
 * digits and a day is the report day. The first days of the history are not judged: with no history every card is new.
 *
 * Other signals over the whole period: one BIN carrying too much of the sales, cards tried again and again in a day
 * (card testing) and the share of sales made at night.
 */
class CardTraffic
{
    public const BY_MERCHANT = 'merchant';

    public const BY_MID = 'mid';

    /** All the companies and MIDs under one client. */
    public const BY_CLIENT = 'client';

    /**
     * Per owner (merchant or MID) and day, within the range.
     *
     * @return Collection<int, array{owner_id: int, day: string, sales: int, cards: int, new_cards: int, new_share: float|null, warming: bool, high: bool}>
     */
    public function daily(CarbonImmutable $from, CarbonImmutable $to, ?int $ownerId = null, string $by = self::BY_MERCHANT): Collection
    {
        $owner = $this->ownerColumn($by);
        $card = $this->cardKey();

        $first = $this->traffic([OperationType::Sale])
            ->selectRaw("{$owner} as owner_id, merchant_operations.card_bin, merchant_operations.card_last4, min(merchant_operations.report_date) as first_day")
            ->groupBy($owner, 'merchant_operations.card_bin', 'merchant_operations.card_last4');

        $days = $this->traffic([OperationType::Sale])
            ->joinSub($first, 'f', fn ($join) => $join
                ->on('f.owner_id', '=', DB::raw($owner))
                ->on('f.card_bin', '=', 'merchant_operations.card_bin')
                ->on('f.card_last4', '=', 'merchant_operations.card_last4'))
            ->whereBetween('merchant_operations.report_date', [$from->toDateString(), $to->toDateString()])
            ->when($ownerId, fn (Builder $q, int $id) => $q->where($owner, $id))
            ->selectRaw("{$owner} as owner_id, merchant_operations.report_date as day, count(*) as sales, count(distinct {$card}) as cards, count(distinct case when f.first_day = merchant_operations.report_date then {$card} end) as new_cards")
            ->groupBy($owner, 'merchant_operations.report_date')
            ->orderBy('merchant_operations.report_date')
            ->get();

        $starts = $this->traffic([OperationType::Sale])
            ->selectRaw("{$owner} as owner_id, min(merchant_operations.report_date) as start")
            ->groupBy($owner)
            ->pluck('start', 'owner_id');

        $warmup = (int) config('sterling.traffic.warmup_days', 7);
        $limit = (float) config('sterling.traffic.new_card_share_limit', 20);

        return $days->map(function ($row) use ($starts, $warmup, $limit) {
            $day = CarbonImmutable::parse($row->day);
            $start = isset($starts[$row->owner_id]) ? CarbonImmutable::parse($starts[$row->owner_id]) : $day;
            $share = (int) $row->cards > 0 ? round((int) $row->new_cards / (int) $row->cards * 100, 1) : null;
            $warming = $day->lessThan($start->addDays($warmup));

            return [
                'owner_id' => (int) $row->owner_id,
                'day' => $day->toDateString(),
                'sales' => (int) $row->sales,
                'cards' => (int) $row->cards,
                'new_cards' => (int) $row->new_cards,
                'new_share' => $share,
                'warming' => $warming,
                'high' => ! $warming && $share !== null && $share > $limit,
            ];
        });
    }

    /**
     * One line per owner for the whole range: the new-card share (days still building history are left out) and
     * the other signals.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function owners(CarbonImmutable $from, CarbonImmutable $to, string $by = self::BY_MERCHANT): Collection
    {
        $limit = (float) config('sterling.traffic.new_card_share_limit', 20);
        $signals = $this->signals($from, $to, $by);
        $labels = $this->labels($by);

        // Merchants that no client owns have no line when the traffic is grouped by client.
        return $this->daily($from, $to, null, $by)->reject(fn (array $d) => $by === self::BY_CLIENT && $d['owner_id'] === 0)->groupBy('owner_id')->map(function (Collection $days, int|string $id) use ($labels, $signals, $limit) {
            $judged = $days->where('warming', false);
            $cards = $judged->sum('cards');
            $new = $judged->sum('new_cards');
            $share = $cards > 0 ? round($new / $cards * 100, 1) : null;
            $signal = $signals[$id] ?? [];

            return [
                ...$labels[$id] ?? ['merchant' => ['public_id' => null, 'name' => "#{$id}"], 'mid' => null],
                'sales' => $days->sum('sales'),
                'cards' => $days->sum('cards'),
                'new_cards' => $days->sum('new_cards'),
                'new_share' => $share,
                'high_days' => $judged->where('high', true)->count(),
                'days' => $days->count(),
                'status' => $share === null ? 'building' : ($share > $limit ? 'high' : 'ok'),
                'signals' => $signal,
                'flags' => $signal['flags'] ?? [],
            ];
        })->sortByDesc(fn (array $row) => ($row['status'] === 'high' ? 1000 : 0) + count($row['flags']) * 100 + ($row['new_share'] ?? -1))->values();
    }

    /**
     * BIN concentration, repeated attempts on one card and night traffic, per owner.
     *
     * @return array<int, array<string, mixed>>
     */
    public function signals(CarbonImmutable $from, CarbonImmutable $to, string $by = self::BY_MERCHANT, ?int $ownerId = null): array
    {
        $owner = $this->ownerColumn($by);
        $range = [$from->toDateString(), $to->toDateString()];
        $binLimit = (float) config('sterling.traffic.bin_share_limit', 30);
        $attemptsLimit = (int) config('sterling.traffic.attempts_per_card_limit', 8);
        $nightLimit = (float) config('sterling.traffic.night_share_limit', 25);
        [$nightFrom, $nightTo] = [(int) config('sterling.traffic.night_from_hour', 0), (int) config('sterling.traffic.night_to_hour', 6)];
        $hour = $this->hourExpr();

        $sales = $this->traffic([OperationType::Sale])
            ->whereBetween('merchant_operations.report_date', $range)
            ->when($ownerId, fn (Builder $q, int $id) => $q->where($owner, $id));

        $totals = (clone $sales)
            ->selectRaw("{$owner} as owner_id, count(*) as sales, sum(case when {$hour} >= {$nightFrom} and {$hour} < {$nightTo} then 1 else 0 end) as night")
            ->groupBy($owner)->get()->keyBy('owner_id');

        $bins = (clone $sales)
            ->selectRaw("{$owner} as owner_id, merchant_operations.card_bin, count(*) as n")
            ->groupBy($owner, 'merchant_operations.card_bin')->get()->groupBy('owner_id');

        // Card testing: the same card tried many times (successful or declined) within one day.
        $tries = $this->traffic([OperationType::Sale, OperationType::Decline])
            ->whereBetween('merchant_operations.report_date', $range)
            ->when($ownerId, fn (Builder $q, int $id) => $q->where($owner, $id))
            ->selectRaw("{$owner} as owner_id, merchant_operations.report_date, merchant_operations.card_bin, merchant_operations.card_last4, count(*) as tries")
            ->groupBy($owner, 'merchant_operations.report_date', 'merchant_operations.card_bin', 'merchant_operations.card_last4')
            ->havingRaw('count(*) >= ?', [$attemptsLimit])
            ->get()->groupBy('owner_id');

        $out = [];
        foreach ($totals as $id => $row) {
            $count = (int) $row->sales;
            $top = ($bins[$id] ?? collect())->sortByDesc('n')->first();
            $binShare = $count > 0 && $top ? round((int) $top->n / $count * 100, 1) : null;
            $nightShare = $count > 0 ? round((int) $row->night / $count * 100, 1) : null;
            $heavy = $tries[$id] ?? collect();

            $flags = array_keys(array_filter([
                'bin' => $binShare !== null && $binShare > $binLimit,
                'attempts' => $heavy->isNotEmpty(),
                'night' => $nightShare !== null && $nightShare > $nightLimit,
            ]));

            $out[(int) $id] = [
                'top_bin' => $top?->card_bin,
                'top_bin_share' => $binShare,
                'bin_limit' => $binLimit,
                'heavy_cards' => $heavy->count(),
                'max_tries' => (int) ($heavy->max('tries') ?? 0),
                'attempts_limit' => $attemptsLimit,
                'night_share' => $nightShare,
                'night_limit' => $nightLimit,
                'flags' => $flags,
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{merchant: array{public_id: string|null, name: string}, mid: string|null}>
     */
    private function labels(string $by): array
    {
        if ($by === self::BY_CLIENT) {
            return Client::query()->get(['id', 'name'])
                ->mapWithKeys(fn (Client $c) => [$c->id => ['merchant' => ['public_id' => null, 'name' => $c->name], 'mid' => null, 'client_id' => $c->id]])->all();
        }

        if ($by === self::BY_MID) {
            return MerchantMid::query()->with('merchant:id,public_id,name')->get(['id', 'mid', 'merchant_id', 'currency'])
                ->mapWithKeys(fn (MerchantMid $m) => [$m->id => [
                    'merchant' => ['public_id' => $m->merchant?->public_id, 'name' => $m->merchant?->name ?? "#{$m->merchant_id}"],
                    'mid' => $m->mid,
                ]])->all();
        }

        return Merchant::query()->get(['id', 'public_id', 'name'])
            ->mapWithKeys(fn (Merchant $m) => [$m->id => ['merchant' => ['public_id' => $m->public_id, 'name' => $m->name], 'mid' => null]])->all();
    }

    /**
     * Payments with a card of the given kinds, one source per MID (the gateway when it has one, else the acquirer)
     * so an operation is never counted on both sides.
     *
     * @param  list<OperationType>  $types
     * @return Builder<MerchantOperation>
     */
    private function traffic(array $types): Builder
    {
        return MerchantOperation::query()
            ->join('merchant_mids as m', 'm.id', '=', 'merchant_operations.merchant_mid_id')
            ->join('merchants as mer', 'mer.id', '=', 'merchant_operations.merchant_id')
            ->leftJoin('companies as co', 'co.id', '=', 'mer.company_id')
            ->whereIn('merchant_operations.operation_type', array_map(fn (OperationType $t) => $t->value, $types))
            ->whereNotNull('merchant_operations.card_bin')
            ->whereNotNull('merchant_operations.card_last4')
            ->where(fn ($q) => $q->where('merchant_operations.role', ProviderType::Gate->value)->orWhereNull('m.gate_provider_id'));
    }

    private function ownerColumn(string $by): string
    {
        return match ($by) {
            self::BY_MID => 'merchant_operations.merchant_mid_id',
            self::BY_CLIENT => 'co.client_id',
            default => 'merchant_operations.merchant_id',
        };
    }

    private function cardKey(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "(merchant_operations.card_bin || '-' || merchant_operations.card_last4)"
            : "concat(merchant_operations.card_bin, '-', merchant_operations.card_last4)";
    }

    /** Hour of the day in UTC, the time the operations are stored in. */
    private function hourExpr(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "cast(strftime('%H', merchant_operations.transaction_at) as integer)"
            : 'hour(merchant_operations.transaction_at)';
    }
}

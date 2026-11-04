<?php

namespace App\Reports;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\Merchant;
use App\Models\MerchantOperation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Internal control of the traffic a merchant brings: how many of the cards paying on a day were never seen on that
 * merchant before. Healthy, trusted traffic has repeat customers, so the share of new cards stays below a limit
 * (20% by default); a merchant above it deserves a look. A card is the BIN plus the last four digits, counted per
 * merchant over everything we hold, and a day is the report day. The first days of a merchant's history are not
 * judged: with no history every card is new.
 */
class CardTraffic
{
    /**
     * Per merchant and day, within the range.
     *
     * @return Collection<int, array{merchant_id: int, day: string, sales: int, cards: int, new_cards: int, new_share: float|null, warming: bool, high: bool}>
     */
    public function daily(CarbonImmutable $from, CarbonImmutable $to, ?int $merchantId = null): Collection
    {
        $card = $this->cardKey();

        $first = $this->sales()
            ->selectRaw('merchant_operations.merchant_id, merchant_operations.card_bin, merchant_operations.card_last4, min(merchant_operations.report_date) as first_day')
            ->groupBy('merchant_operations.merchant_id', 'merchant_operations.card_bin', 'merchant_operations.card_last4');

        $days = $this->sales()
            ->joinSub($first, 'f', fn ($join) => $join
                ->on('f.merchant_id', '=', 'merchant_operations.merchant_id')
                ->on('f.card_bin', '=', 'merchant_operations.card_bin')
                ->on('f.card_last4', '=', 'merchant_operations.card_last4'))
            ->whereBetween('merchant_operations.report_date', [$from->toDateString(), $to->toDateString()])
            ->when($merchantId, fn (Builder $q, int $id) => $q->where('merchant_operations.merchant_id', $id))
            ->selectRaw("merchant_operations.merchant_id, merchant_operations.report_date as day, count(*) as sales, count(distinct {$card}) as cards, count(distinct case when f.first_day = merchant_operations.report_date then {$card} end) as new_cards")
            ->groupBy('merchant_operations.merchant_id', 'merchant_operations.report_date')
            ->orderBy('merchant_operations.report_date')
            ->get();

        $starts = $this->sales()
            ->selectRaw('merchant_operations.merchant_id, min(merchant_operations.report_date) as start')
            ->groupBy('merchant_operations.merchant_id')
            ->pluck('start', 'merchant_id');

        $warmup = (int) config('sterling.traffic.warmup_days', 7);
        $limit = (float) config('sterling.traffic.new_card_share_limit', 20);

        return $days->map(function ($row) use ($starts, $warmup, $limit) {
            $day = CarbonImmutable::parse($row->day);
            $start = isset($starts[$row->merchant_id]) ? CarbonImmutable::parse($starts[$row->merchant_id]) : $day;
            $share = (int) $row->cards > 0 ? round((int) $row->new_cards / (int) $row->cards * 100, 1) : null;
            $warming = $day->lessThan($start->addDays($warmup));

            return [
                'merchant_id' => (int) $row->merchant_id,
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
     * One line per merchant for the whole range: days still building history are left out of the share.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function merchants(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $limit = (float) config('sterling.traffic.new_card_share_limit', 20);
        $names = Merchant::query()->pluck('name', 'id');
        $publicIds = Merchant::query()->pluck('public_id', 'id');

        return $this->daily($from, $to)->groupBy('merchant_id')->map(function (Collection $days, int|string $id) use ($names, $publicIds, $limit) {
            $judged = $days->where('warming', false);
            $cards = $judged->sum('cards');
            $new = $judged->sum('new_cards');
            $share = $cards > 0 ? round($new / $cards * 100, 1) : null;

            return [
                'merchant' => ['public_id' => $publicIds[$id] ?? null, 'name' => $names[$id] ?? "#{$id}"],
                'sales' => $days->sum('sales'),
                'cards' => $days->sum('cards'),
                'new_cards' => $days->sum('new_cards'),
                'judged_cards' => $cards,
                'new_share' => $share,
                'high_days' => $judged->where('high', true)->count(),
                'days' => $days->count(),
                'status' => $share === null ? 'building' : ($share > $limit ? 'high' : 'ok'),
            ];
        })->sortByDesc(fn (array $row) => $row['new_share'] ?? -1)->values();
    }

    /**
     * Successful payments with a card, one source per MID (the gateway when it has one, else the acquirer) so a sale
     * is never counted on both sides.
     *
     * @return Builder<MerchantOperation>
     */
    private function sales(): Builder
    {
        return MerchantOperation::query()
            ->join('merchant_mids as m', 'm.id', '=', 'merchant_operations.merchant_mid_id')
            ->where('merchant_operations.operation_type', OperationType::Sale->value)
            ->whereNotNull('merchant_operations.card_bin')
            ->whereNotNull('merchant_operations.card_last4')
            ->where(fn ($q) => $q->where('merchant_operations.role', ProviderType::Gate->value)->orWhereNull('m.gate_provider_id'));
    }

    private function cardKey(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "(merchant_operations.card_bin || '-' || merchant_operations.card_last4)"
            : "concat(merchant_operations.card_bin, '-', merchant_operations.card_last4)";
    }
}

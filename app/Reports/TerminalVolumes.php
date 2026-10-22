<?php

namespace App\Reports;

use App\Enums\MidStatus;
use App\Enums\ProviderType;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Successful gateway volume per terminal (MID) for a day, a month or any
 * range, against the terminal's daily norm and monthly processing limit.
 * The gateway is the system that sees every attempt, so only its rows count.
 */
class TerminalVolumes
{
    /**
     * One row per terminal that has a gateway.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(CarbonImmutable $from, CarbonImmutable $to, ?int $midId = null): Collection
    {
        $stats = MerchantOperation::query()->toBase()
            ->where('role', ProviderType::Gate->value)
            ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('merchant_mid_id')
            ->when($midId, fn ($q, int $id) => $q->where('merchant_mid_id', $id))
            ->selectRaw('merchant_mid_id, '.$this->aggregates())
            ->groupBy('merchant_mid_id')
            ->get()
            ->keyBy('merchant_mid_id');

        $days = (int) $from->diffInDays($to) + 1;

        return MerchantMid::query()
            ->with(['merchant:id,public_id,name', 'bankProvider:id,name', 'gateProvider:id,name'])
            ->whereNotNull('gate_provider_id')
            ->when($midId, fn ($q, int $id) => $q->whereKey($id))
            ->orderBy('mid')
            ->get()
            ->map(function (MerchantMid $mid) use ($stats, $days, $from) {
                $s = $stats->get($mid->id);
                $volume = round((float) ($s->volume ?? 0), 2);
                $approved = (int) ($s->approved ?? 0);
                $declined = (int) ($s->declined ?? 0);
                $limit = $mid->processing_limit !== null ? (float) $mid->processing_limit : null;
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
        $perDay = MerchantOperation::query()->toBase()
            ->where('role', ProviderType::Gate->value)
            ->where('merchant_mid_id', $midId)
            ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('report_date, '.$this->aggregates())
            ->groupBy('report_date')
            ->get()
            ->keyBy(fn ($r) => CarbonImmutable::parse($r->report_date)->toDateString());

        $mid = MerchantMid::query()->find($midId);
        $limit = $mid?->processing_limit !== null ? (float) $mid->processing_limit : null;

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

        if ($mid->processing_limit !== null && (float) $mid->processing_limit > 0) {
            return ['amount' => round((float) $mid->processing_limit / $day->daysInMonth, 2), 'derived' => true];
        }

        return null;
    }

    private function aggregates(): string
    {
        return "sum(case when operation_type = 'sale' then 1 else 0 end) as approved, "
            ."sum(case when operation_type = 'sale' then amount else 0 end) as volume, "
            ."sum(case when operation_type = 'decline' then 1 else 0 end) as declined";
    }
}

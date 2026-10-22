<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProviderType;
use App\Http\Controllers\Controller;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How much successful volume each terminal (MID) took through the gateway, by
 * day and month, against its monthly processing limit.
 */
class VolumeController extends Controller
{
    public function index(Request $request): Response
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'mid' => ['nullable', 'integer'],
        ]);

        $timezone = config('sterling.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $month = isset($data['month']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01', $timezone)->startOfDay() : $today->startOfMonth();
        $from = $month->startOfMonth();
        $to = $month->endOfMonth()->startOfDay();

        // Gateway rows only: the gateway is the system that sees every attempt.
        $stats = MerchantOperation::query()->toBase()
            ->where('role', ProviderType::Gate->value)
            ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('merchant_mid_id')
            ->selectRaw('merchant_mid_id, '
                ."sum(case when operation_type = 'sale' then 1 else 0 end) as approved, "
                ."sum(case when operation_type = 'sale' then amount else 0 end) as volume, "
                ."sum(case when operation_type = 'decline' then 1 else 0 end) as declined")
            ->groupBy('merchant_mid_id')
            ->get()
            ->keyBy('merchant_mid_id');

        $elapsed = max(1, (int) min($from->diffInDays($today) + 1, $from->daysInMonth));
        $isCurrent = $today->between($from, $to);

        $rows = MerchantMid::query()
            ->with(['merchant:id,public_id,name', 'bankProvider:id,name', 'gateProvider:id,name'])
            ->whereNotNull('gate_provider_id')
            ->orderBy('mid')
            ->get()
            ->map(function (MerchantMid $mid) use ($stats, $elapsed, $isCurrent, $from) {
                $s = $stats->get($mid->id);
                $volume = round((float) ($s->volume ?? 0), 2);
                $approved = (int) ($s->approved ?? 0);
                $declined = (int) ($s->declined ?? 0);
                $limit = $mid->processing_limit !== null ? (float) $mid->processing_limit : null;
                // At the pace of the days so far, where the month ends.
                $projected = $isCurrent ? round($volume / $elapsed * $from->daysInMonth, 2) : $volume;

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
                    'projected' => $projected,
                ];
            })
            // Terminals with volume first, then by how full they are.
            ->filter(fn (array $r) => $r['volume'] > 0 || $r['declined'] > 0 || $r['status'] === 'active')
            ->sortByDesc('volume')
            ->values();

        $totals = $rows->groupBy('currency')->map(fn ($group, string $currency) => [
            'currency' => $currency,
            'terminals' => $group->where('volume', '>', 0)->count(),
            'approved' => $group->sum('approved'),
            'volume' => round($group->sum('volume'), 2),
        ])->values();

        return Inertia::render('admin/volumes/index', [
            'month' => $from->format('Y-m'),
            'previous' => $from->subMonth()->format('Y-m'),
            'next' => $from->addMonth()->lessThanOrEqualTo($today) ? $from->addMonth()->format('Y-m') : null,
            'rows' => $rows,
            'totals' => $totals,
            'selected' => isset($data['mid']) ? $this->days($rows->firstWhere('id', (int) $data['mid']), $from, $to) : null,
        ]);
    }

    /**
     * Day by day successful volume of one terminal, with the running share of its limit.
     *
     * @param  array<string, mixed>|null  $row
     * @return array<string, mixed>|null
     */
    private function days(?array $row, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        if ($row === null) {
            return null;
        }

        $perDay = MerchantOperation::query()->toBase()
            ->where('role', ProviderType::Gate->value)
            ->where('merchant_mid_id', $row['id'])
            ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('report_date, '
                ."sum(case when operation_type = 'sale' then 1 else 0 end) as approved, "
                ."sum(case when operation_type = 'sale' then amount else 0 end) as volume, "
                ."sum(case when operation_type = 'decline' then 1 else 0 end) as declined")
            ->groupBy('report_date')
            ->get()
            ->keyBy(fn ($r) => CarbonImmutable::parse($r->report_date)->toDateString());

        $running = 0.0;
        $days = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $d = $perDay->get($day->toDateString());
            $volume = round((float) ($d->volume ?? 0), 2);
            $running += $volume;
            $days[] = [
                'date' => $day->toDateString(),
                'approved' => (int) ($d->approved ?? 0),
                'declined' => (int) ($d->declined ?? 0),
                'volume' => $volume,
                'cumulative' => round($running, 2),
                'cumulative_percent' => $row['limit'] ? round($running / $row['limit'] * 100, 1) : null,
            ];
        }

        return ['terminal' => $row, 'days' => $days];
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\TerminalVolumes;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How much successful volume each terminal (MID) took through the gateway:
 * for one day (Today, Yesterday, any date) or a month, against the daily
 * norm and the monthly processing limit, and the daily amount still needed
 * to fill each terminal by the end of the month (the plan).
 */
class VolumeController extends Controller
{
    private const HISTORY_DAYS = 14;

    public function index(Request $request, TerminalVolumes $volumes): Response
    {
        $data = $request->validate([
            'view' => ['nullable', 'in:day,month,plan'],
            'day' => ['nullable', 'date_format:Y-m-d'],
            'month' => ['nullable', 'date_format:Y-m'],
            'mid' => ['nullable', 'integer'],
        ]);

        $timezone = config('sterling.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $view = $data['view'] ?? 'day';

        if ($view === 'plan') {
            return Inertia::render('admin/volumes/index', [
                'view' => 'plan',
                'day' => null,
                'month' => $today->format('Y-m'),
                'today' => $today->toDateString(),
                'isToday' => false,
                'shortcuts' => $this->shortcuts($today),
                'previous' => $today->toDateString(),
                'next' => null,
                'rows' => [],
                'totals' => [],
                'selected' => null,
                'plan' => [
                    'as_of' => $today->toDateString(),
                    'days_left' => $today->daysInMonth - $today->day,
                    'days_in_month' => $today->daysInMonth,
                    'terminals' => $volumes->plan($today),
                ],
            ]);
        }

        if ($view === 'month') {
            $from = isset($data['month']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01', $timezone)->startOfDay() : $today->startOfMonth();
            $from = $from->startOfMonth();
            $to = $from->endOfMonth()->startOfDay();
            $seriesFrom = $from;
            $seriesTo = $to;
            $previous = $from->subMonth()->format('Y-m');
            $next = $from->addMonth()->lessThanOrEqualTo($today) ? $from->addMonth()->format('Y-m') : null;
        } else {
            $from = $to = isset($data['day']) ? min(CarbonImmutable::createFromFormat('Y-m-d', $data['day'], $timezone)->startOfDay(), $today) : $today;
            $seriesTo = $to;
            $seriesFrom = $to->subDays(self::HISTORY_DAYS - 1);
            $previous = $to->subDay()->toDateString();
            $next = $to->lessThan($today) ? $to->addDay()->toDateString() : null;
        }

        $rows = $volumes->rows($from, $to);

        return Inertia::render('admin/volumes/index', [
            'view' => $view,
            'day' => $view === 'day' ? $to->toDateString() : null,
            'month' => $view === 'month' ? $from->format('Y-m') : $to->format('Y-m'),
            'today' => $today->toDateString(),
            'isToday' => $view === 'day' && $to->equalTo($today),
            'shortcuts' => $this->shortcuts($today),
            'previous' => $previous,
            'next' => $next,
            'plan' => null,
            'rows' => $rows,
            'totals' => $this->totals($rows),
            'selected' => isset($data['mid']) && ($row = $rows->firstWhere('id', (int) $data['mid'])) !== null
                ? ['terminal' => $row, 'days' => $volumes->series($row['id'], $seriesFrom, $seriesTo)]
                : null,
        ]);
    }

    /**
     * Today, Yesterday and the days before, for one-click navigation.
     *
     * @return list<array{date: string, label: string}>
     */
    private function shortcuts(CarbonImmutable $today): array
    {
        return array_map(fn (int $back) => [
            'date' => $today->subDays($back)->toDateString(),
            'label' => match ($back) {
                0 => 'Today',
                1 => 'Yesterday',
                default => $today->subDays($back)->format('D j M'),
            },
        ], range(0, 6));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function totals(Collection $rows): array
    {
        return $rows->groupBy('currency')->map(fn (Collection $group, string $currency) => [
            'currency' => $currency,
            'terminals' => $group->where('volume', '>', 0)->count(),
            'approved' => $group->sum('approved'),
            'declined' => $group->sum('declined'),
            'volume' => round($group->sum('volume'), 2),
            'norm' => $group->contains(fn (array $r) => $r['norm'] !== null) ? round($group->sum('norm'), 2) : null,
        ])->values()->all();
    }
}

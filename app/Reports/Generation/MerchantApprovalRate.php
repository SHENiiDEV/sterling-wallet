<?php

namespace App\Reports\Generation;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\Merchant;
use App\Models\MerchantOperation;
use Carbon\CarbonImmutable;

/**
 * The approval rate of a merchant by day, week or month, from the gateway's payment attempts (Corefy): approved sales
 * over all attempts. Routing errors never reached a bank, so they are not attempts (config traffic_report).
 */
class MerchantApprovalRate
{
    public const GROUPS = ['day' => 30, 'week' => 12, 'month' => 12];

    /**
     * @return array{group: string, rows: list<array<string, mixed>>, totals: array<string, mixed>, routing_errors: int}
     */
    public function for(Merchant $merchant, string $group = 'week', ?CarbonImmutable $now = null): array
    {
        $group = array_key_exists($group, self::GROUPS) ? $group : 'week';
        $zone = config('sterling.timezone');
        $now = ($now ?? CarbonImmutable::now())->setTimezone($zone);

        $periods = $this->periods($group, $now);
        $start = $periods[array_key_first($periods)]['from']->startOfDay()->utc();

        $ignored = array_map('strtolower', (array) config('sterling.traffic_report.ignore_resolutions', ['routing_error']));
        $marks = implode(',', array_fill(0, max(1, count($ignored)), '?'));

        // One row per UTC hour: whole-hour offsets move it to the right local day exactly, and the query stays small.
        $hours = MerchantOperation::query()->toBase()
            ->where('role', ProviderType::Gate->value)
            ->where('merchant_id', $merchant->id)
            ->whereIn('operation_type', [OperationType::Sale->value, OperationType::Decline->value])
            ->where('transaction_at', '>=', $start->format('Y-m-d H:i:s'))
            ->selectRaw("SUBSTR(CAST(transaction_at AS CHAR), 1, 13) as hour, operation_type as kind, CASE WHEN LOWER(TRIM(COALESCE(resolution, ''))) IN ({$marks}) THEN 1 ELSE 0 END as routing, COUNT(*) as total", $ignored ?: [''])
            ->groupBy('hour', 'kind', 'routing')
            ->get();

        $rows = array_map(fn (array $p) => [...$p, 'attempts' => 0, 'approved' => 0, 'declined' => 0], $periods);
        $routing = 0;
        foreach ($hours as $hour) {
            if ((int) $hour->routing === 1) {
                $routing += (int) $hour->total;

                continue;
            }

            $day = CarbonImmutable::createFromFormat('Y-m-d H', (string) $hour->hour, 'UTC')->setTimezone($zone)->startOfDay();
            $key = $this->key($group, $day);
            if (! isset($rows[$key])) {
                continue;
            }
            $rows[$key]['attempts'] += (int) $hour->total;
            $rows[$key][$hour->kind === OperationType::Sale->value ? 'approved' : 'declined'] += (int) $hour->total;
        }

        $list = array_map(fn (array $r) => [
            'label' => $r['label'],
            'from' => $r['from']->toDateString(),
            'to' => $r['to']->toDateString(),
            'attempts' => $r['attempts'],
            'approved' => $r['approved'],
            'declined' => $r['declined'],
            'rate' => $r['attempts'] > 0 ? round($r['approved'] / $r['attempts'] * 100, 1) : null,
        ], array_values($rows));

        $attempts = array_sum(array_column($list, 'attempts'));
        $approved = array_sum(array_column($list, 'approved'));

        return [
            'group' => $group,
            'rows' => $list,
            'totals' => [
                'attempts' => $attempts,
                'approved' => $approved,
                'declined' => $attempts - $approved,
                'rate' => $attempts > 0 ? round($approved / $attempts * 100, 1) : null,
            ],
            'routing_errors' => $routing,
        ];
    }

    /**
     * The periods, oldest first, keyed like key().
     *
     * @return array<string, array{label: string, from: CarbonImmutable, to: CarbonImmutable}>
     */
    private function periods(string $group, CarbonImmutable $now): array
    {
        $periods = [];
        $count = self::GROUPS[$group];
        for ($i = $count - 1; $i >= 0; $i--) {
            $anchor = match ($group) {
                'day' => $now->startOfDay()->subDays($i),
                'week' => $now->startOfWeek()->subWeeks($i),
                default => $now->startOfMonth()->subMonthsNoOverflow($i),
            };
            $from = $anchor;
            $to = match ($group) {
                'day' => $anchor,
                'week' => $anchor->addDays(6),
                default => $anchor->endOfMonth()->startOfDay(),
            };
            $periods[$this->key($group, $anchor)] = [
                'label' => match ($group) {
                    'day' => $anchor->format('D d M'),
                    'week' => $from->format('d M').' – '.$to->format('d M'),
                    default => $anchor->format('M Y'),
                },
                'from' => $from,
                'to' => $to,
            ];
        }

        return $periods;
    }

    private function key(string $group, CarbonImmutable $day): string
    {
        return match ($group) {
            'day' => $day->toDateString(),
            'week' => $day->startOfWeek()->toDateString(),
            default => $day->startOfMonth()->toDateString(),
        };
    }
}

<?php

namespace App\Bots\Connectors;

use App\Bots\RangeRuns;
use App\Bots\Target;
use App\Models\IntegrationAccount;
use App\Reports\ReportDateResolver;
use App\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Corefy (Paycore dashboard): exports payment invoices of one commerce
 * account for the report period. One target per commerce account and period.
 */
class CorefyExportConnector extends PlaywrightConnector implements RangeRuns
{
    public function code(): string
    {
        return 'corefy-export';
    }

    public function label(): string
    {
        return 'Corefy export (Paycore dashboard)';
    }

    public function targetsForPeriod(IntegrationAccount $account, ReportPeriod $period, Collection $mids): iterable
    {
        // A MID may front several commerce accounts: each is exported on its own.
        $byAccount = [];
        foreach ($mids as $mid) {
            foreach ($mid->gateAccounts() ?: [$mid->mid] as $commerceAccount) {
                $byAccount[$commerceAccount][] = $mid;
            }
        }

        foreach ($byAccount as $commerceAccount => $group) {
            yield new Target($account, $period, collect($group)->values(), [
                'key' => (string) $commerceAccount,
                'commerce_account' => (string) $commerceAccount,
            ]);
        }
    }

    /**
     * History in a few runs: the range is cut into whole report periods (a Friday–Sunday bundle stays together), the
     * periods into chunks of about `sterling.bots.range_days_per_run` days, and each chunk is one export per commerce
     * account that the bot splits into one file per period.
     */
    public function rangeTargets(IntegrationAccount $account, CarbonImmutable $from, CarbonImmutable $to, Collection $mids): iterable
    {
        $resolver = app(ReportDateResolver::class);
        $periods = [];
        for ($cursor = $from; $cursor->lessThanOrEqualTo($to); $cursor = $periods[array_key_last($periods)]->to->addDay()) {
            $periods[] = $resolver->periodFor($cursor);
        }

        $limit = max(1, (int) config('sterling.bots.range_days_per_run', 14));
        $chunks = [];
        $current = [];
        $days = 0;
        foreach ($periods as $period) {
            $length = (int) $period->from->diffInDays($period->to) + 1;
            if ($current !== [] && $days + $length > $limit) {
                $chunks[] = $current;
                $current = [];
                $days = 0;
            }
            $current[] = $period;
            $days += $length;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        $byAccount = [];
        foreach ($mids as $mid) {
            foreach ($mid->gateAccounts() ?: [$mid->mid] as $commerceAccount) {
                $byAccount[$commerceAccount][] = $mid;
            }
        }

        foreach ($chunks as $chunk) {
            $first = $chunk[0];
            $last = $chunk[array_key_last($chunk)];
            $span = new ReportPeriod($last->to, $first->from, $last->to);

            foreach ($byAccount as $commerceAccount => $group) {
                yield new Target($account, $span, collect($group)->values(), [
                    'key' => "range:{$first->from->toDateString()}:{$last->to->toDateString()}:{$commerceAccount}",
                    'commerce_account' => (string) $commerceAccount,
                    'periods' => array_map(fn (ReportPeriod $p) => ['report_date' => $p->reportDate->toDateString(), 'from' => $p->from->toDateString(), 'to' => $p->to->toDateString()], $chunk),
                ]);
            }
        }
    }

    protected function input(Target $target): array
    {
        $timezone = $target->account->provider->timezone;

        return [
            'periods' => $target->params['periods'] ?? null,
            'login_url' => $target->account->login_url ?: 'https://dashboard.paycore.io/login',
            'dashboard_url' => $target->account->setting('dashboard_url', 'https://dashboard.paycore.io'),
            'commerce_account' => $target->params['commerce_account'] ?? null,
            // Day bounds in the provider's time zone, as Unix timestamps for the dashboard filter.
            // A day and a day on each side: the gateway filters by its own clock, so a window that is exactly the day
            // can miss the last hours or bring the evening before. Every payment is booked to its own day on arrival.
            'gte' => CarbonImmutable::parse($target->period->from->toDateString(), $timezone)->subDay()->startOfDay()->getTimestamp(),
            'lt' => CarbonImmutable::parse($target->period->to->toDateString(), $timezone)->addDays(2)->startOfDay()->getTimestamp(),
        ];
    }
}

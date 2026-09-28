<?php

namespace App\Bots\Connectors;

use App\Bots\Target;
use App\Models\IntegrationAccount;
use App\Models\MerchantMid;
use App\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Corefy (Paycore dashboard): exports payment invoices of one commerce
 * account for the report period. One target per commerce account and period.
 */
class CorefyExportConnector extends PlaywrightConnector
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
        foreach ($mids->groupBy(fn (MerchantMid $m) => $m->gate_mid ?? $m->mid) as $commerceAccount => $group) {
            yield new Target($account, $period, $group->values(), [
                'key' => (string) $commerceAccount,
                'commerce_account' => (string) $commerceAccount,
            ]);
        }
    }

    protected function input(Target $target): array
    {
        $timezone = $target->account->provider->timezone;

        return [
            'login_url' => $target->account->login_url ?: 'https://dashboard.paycore.io/login',
            'dashboard_url' => $target->account->setting('dashboard_url', 'https://dashboard.paycore.io'),
            'commerce_account' => $target->params['commerce_account'] ?? null,
            // Day bounds in the provider's time zone, as Unix timestamps for the dashboard filter.
            'gte' => CarbonImmutable::parse($target->period->from->toDateString(), $timezone)->startOfDay()->getTimestamp(),
            'lt' => CarbonImmutable::parse($target->period->to->toDateString(), $timezone)->addDay()->startOfDay()->getTimestamp(),
        ];
    }
}

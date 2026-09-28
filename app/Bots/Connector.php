<?php

namespace App\Bots;

use App\Models\BotRun;
use App\Models\IntegrationAccount;
use App\Models\MerchantMid;
use App\Reports\ReportPeriod;
use Illuminate\Support\Collection;

/**
 * A bot that fetches one provider's reports. Adding a provider = one
 * implementation (usually a PlaywrightConnector + bots/{code}.mjs).
 */
interface Connector
{
    /** Key stored in providers.connector and integration_accounts.connector, e.g. `corefy-export`. */
    public function code(): string;

    public function label(): string;

    /**
     * Work that is due now across all active accounts of this connector.
     *
     * @return iterable<Target>
     */
    public function pendingTargets(): iterable;

    /**
     * Targets for one period of one account (used by the scheduler and "run now").
     *
     * @param  Collection<int, MerchantMid>  $mids
     * @return iterable<Target>
     */
    public function targetsForPeriod(IntegrationAccount $account, ReportPeriod $period, Collection $mids): iterable;

    public function run(Target $target, BotRun $run): BotResult;
}

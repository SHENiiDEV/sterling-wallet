<?php

namespace App\Bots;

use App\Models\IntegrationAccount;
use App\Models\MerchantMid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Connectors that can load a whole range of days (history) in a few runs, instead of one run per report day:
 * the range is cut into whole report periods (a Friday–Sunday bundle is never split) and the periods into chunks.
 */
interface RangeRuns
{
    /**
     * @param  Collection<int, MerchantMid>  $mids
     * @return iterable<Target>
     */
    public function rangeTargets(IntegrationAccount $account, CarbonImmutable $from, CarbonImmutable $to, Collection $mids): iterable;
}

<?php

namespace App\Console\Commands;

use App\Bots\BotDispatcher;
use App\Bots\Connectors\CorefyApiConnector;
use App\Models\IntegrationAccount;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('corefy:refresh {--days=1 : How many days back from today to fetch again (1 = yesterday and today)}')]
#[Description('Fetch the Corefy payments of every commerce account again, from the start of yesterday to now, whatever is already stored')]
class CorefyRefreshCommand extends Command
{
    public function handle(BotDispatcher $dispatcher): int
    {
        // Days are the gateway market's days (Riga): yesterday 00:00 up to the end of today, so the late hours of the
        // acquirer's UTC day are in as well. Rows already stored are updated, not repeated.
        $today = CarbonImmutable::now(config('sterling.timezone'))->startOfDay();
        $from = $today->subDays(max(0, (int) $this->option('days')));

        $queued = 0;
        IntegrationAccount::query()
            ->with('provider')
            ->where('connector', CorefyApiConnector::CODE)
            ->where('is_active', true)
            ->get()
            ->filter(fn (IntegrationAccount $a) => $a->provider->is_active)
            ->each(function (IntegrationAccount $account) use ($dispatcher, $from, $today, &$queued) {
                try {
                    $queued += count($dispatcher->queueRange($account, $from, $today));
                } catch (InvalidArgumentException $e) {
                    $this->warn("{$account->id}: {$e->getMessage()}");
                }
            });

        $this->info("Queued {$queued} run(s) for {$from->toDateString()} – {$today->toDateString()}.");

        return self::SUCCESS;
    }
}

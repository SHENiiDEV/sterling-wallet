<?php

namespace App\Bots\Connectors;

use App\Bots\BotResult;
use App\Bots\Connector;
use App\Bots\PlaywrightRunner;
use App\Bots\Target;
use App\Models\BotRun;
use App\Models\IntegrationAccount;
use App\Models\MerchantMid;
use App\Reports\Pending\PendingReportFinder;
use App\Reports\ReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Base for portal bots: finds due work from missing daily_report_sources
 * and hands one target at a time to a Playwright script.
 */
abstract class PlaywrightConnector implements Connector
{
    public function __construct(
        protected PendingReportFinder $pending,
        protected PlaywrightRunner $runner,
    ) {}

    /**
     * Script-specific part of the payload.
     *
     * @return array<string, mixed>
     */
    abstract protected function input(Target $target): array;

    public function pendingTargets(): iterable
    {
        $accounts = IntegrationAccount::query()
            ->with('provider')
            ->where('connector', $this->code())
            ->where('is_active', true)
            ->get();

        foreach ($accounts as $account) {
            if (! $account->provider->is_active) {
                continue;
            }
            yield from $this->targetsFor($account);
        }
    }

    /**
     * Default: one target per report period, covering every MID of the
     * account that still misses it.
     *
     * @return iterable<Target>
     */
    public function targetsFor(IntegrationAccount $account): iterable
    {
        $byPeriod = [];
        foreach ($this->pending->find($account->provider, $account->coveredMids()->get()) as $entry) {
            foreach ($entry['periods'] as $period) {
                $key = $period->reportDate->toDateString();
                $byPeriod[$key] ??= ['period' => $period, 'mids' => new Collection];
                $byPeriod[$key]['mids']->push($entry['mid']);
            }
        }
        ksort($byPeriod);

        foreach ($byPeriod as $group) {
            yield from $this->targetsForPeriod($account, $group['period'], $group['mids']);
        }
    }

    public function targetsForPeriod(IntegrationAccount $account, ReportPeriod $period, Collection $mids): iterable
    {
        yield new Target($account, $period, $mids->values());
    }

    public function run(Target $target, BotRun $run): BotResult
    {
        $directory = Storage::disk('local')->path("bots/{$run->id}");
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $account = $target->account;

        return $this->runner->run($this->code(), [
            'run_id' => $run->id,
            'login_url' => $account->login_url,
            'username' => $account->username,
            'password' => $account->password,
            'totp_secret' => $account->totp_secret,
            'headless' => (bool) config('sterling.bots.headless'),
            'proxy' => $account->setting('proxy', config('sterling.bots.proxy')),
            'download_dir' => $directory,
            'screenshot_dir' => $directory,
            'report_date' => $target->period->reportDate->toDateString(),
            'from' => $target->period->from->toDateString(),
            'to' => $target->period->to->toDateString(),
            'timezone' => $account->provider->timezone,
            'mids' => $target->mids->map(fn (MerchantMid $m) => ['id' => $m->id, 'mid' => $m->mid, 'gate_mid' => $m->gate_mid])->all(),
            ...$this->input($target),
        ]);
    }
}

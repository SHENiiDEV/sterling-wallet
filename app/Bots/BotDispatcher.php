<?php

namespace App\Bots;

use App\Enums\BotRunStatus;
use App\Jobs\RunConnectorJob;
use App\Models\BotRun;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Turns due targets into queued bot runs — at most one active run per
 * (connector, account, target key).
 */
class BotDispatcher
{
    public function __construct(private ConnectorRegistry $connectors) {}

    /**
     * @return list<BotRun>
     */
    public function dispatchDue(?string $connectorCode = null, bool $custom = false): array
    {
        $schedule = app(BotSchedule::class);
        $runs = [];
        foreach ($this->connectors->all() as $code => $connector) {
            if ($connectorCode !== null && $code !== $connectorCode) {
                continue;
            }
            foreach ($connector->pendingTargets() as $target) {
                // Accounts with their own rhythm are started by the every-minute pass, the rest by their connector's schedule.
                if (BotSchedule::isCustom($target->account) !== $custom || ($custom && ! $schedule->due($target->account))) {
                    continue;
                }
                if ($run = $this->queue($connector, $target)) {
                    $runs[] = $run;
                }
            }
        }

        return $runs;
    }

    public function queue(Connector $connector, Target $target, ?User $by = null): ?BotRun
    {
        $lock = Cache::lock("bots:{$connector->code()}:{$target->account->id}:{$target->key()}", 30);
        if (! $lock->get()) {
            return null;
        }

        try {
            $active = BotRun::query()
                ->where('connector', $connector->code())
                ->where('integration_account_id', $target->account->id)
                ->where('target_key', $target->key())
                ->whereIn('status', [BotRunStatus::Queued, BotRunStatus::Running])
                ->exists();
            if ($active) {
                return null;
            }

            $run = BotRun::query()->create([
                'connector' => $connector->code(),
                'integration_account_id' => $target->account->id,
                'report_date' => $target->period->reportDate,
                'target_key' => $target->key(),
                'target' => $target->toArray(),
                'status' => BotRunStatus::Queued,
                'triggered_by' => $by?->id,
            ]);
        } finally {
            $lock->release();
        }

        RunConnectorJob::dispatch($run->id);

        return $run;
    }

    /**
     * Fetch an arbitrary range of days in one run. Only for connectors that book every row to its own day
     * (the wallet API), since the run's report date is just the last day of the range.
     */
    public function queueRange(IntegrationAccount $account, CarbonImmutable $from, CarbonImmutable $to, ?User $by = null): ?BotRun
    {
        $connector = $this->connectors->get($account->connector);
        if (! $connector instanceof IngestsByRowDate) {
            throw new InvalidArgumentException("{$account->connector} cannot fetch a range of days.");
        }

        $mids = $account->coveredMids()->get();
        if ($mids->isEmpty()) {
            throw new InvalidArgumentException('This account covers no MIDs yet.');
        }

        $period = new ReportPeriod($to, $from, $to);
        $key = 'range:'.$from->toDateString().':'.$to->toDateString();

        return $this->queue($connector, new Target($account, $period, $mids, ['key' => $key]), $by);
    }

    /**
     * Queue the same target again (the "retry" button).
     */
    public function retry(BotRun $run, ?User $by = null): ?BotRun
    {
        $run->loadMissing('account.provider');
        if ($run->account === null) {
            return null;
        }

        return $this->queue($this->connectors->get($run->connector), Target::fromArray($run->account, $run->target ?? []), $by);
    }
}

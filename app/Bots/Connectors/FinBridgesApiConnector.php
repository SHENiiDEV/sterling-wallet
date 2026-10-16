<?php

namespace App\Bots\Connectors;

use App\Bots\BotResult;
use App\Bots\IngestsByRowDate;
use App\Bots\Target;
use App\Enums\BotRunStatus;
use App\Models\BotRun;
use App\Models\IntegrationAccount;
use App\Models\MerchantOperation;
use App\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * FinBridges C2B wallet. Talks to the public API (no browser): one account is
 * one client wallet. Login = apiKey, Password = apiSecret, settings carry
 * `client_id`, `wallet_id` and `base_url`.
 *
 * It is polled every few minutes over a short window (today and yesterday,
 * `lookback_days` in the settings): only transactions we do not have yet are
 * passed on, each one is booked to the day it happened.
 */
class FinBridgesApiConnector extends PlaywrightConnector implements IngestsByRowDate
{
    public function code(): string
    {
        return 'finbridges-api';
    }

    public function label(): string
    {
        return 'FinBridges API (wallet polling)';
    }

    public function pendingTargets(): iterable
    {
        $accounts = IntegrationAccount::query()
            ->with('provider')
            ->where('connector', $this->code())
            ->where('is_active', true)
            ->get();

        foreach ($accounts as $account) {
            $mids = $account->coveredMids()->get();
            if (! $account->provider->is_active || $mids->isEmpty()) {
                continue;
            }

            $today = CarbonImmutable::now($account->provider->timezone ?: config('sterling.timezone'))->startOfDay();
            $days = max(0, (int) $account->setting('lookback_days', 1));

            yield new Target($account, new ReportPeriod($today, $today->subDays($days), $today), $mids, ['key' => 'poll']);
        }
    }

    protected function input(Target $target): array
    {
        return [
            'client_id' => $target->account->setting('client_id'),
            'wallet_id' => $target->account->setting('wallet_id'),
        ];
    }

    public function run(Target $target, BotRun $run): BotResult
    {
        $result = parent::run($target, $run);
        if ($result->status !== BotRunStatus::Succeeded || $result->files === []) {
            return $result;
        }

        $kept = 0;
        $files = [];
        foreach ($result->files as $path) {
            $data = json_decode((string) file_get_contents($path), true);
            $list = is_array($data) ? ($data['list'] ?? []) : [];

            $known = MerchantOperation::query()
                ->where('provider_id', $target->account->provider_id)
                ->whereIn('payment_id', array_filter(array_map(fn ($tx) => $tx['transactionNumber'] ?? $tx['externalTransactionId'] ?? null, $list)))
                ->pluck('payment_id')
                ->flip();

            $fresh = array_values(array_filter($list, fn ($tx) => ! $known->has($tx['transactionNumber'] ?? $tx['externalTransactionId'] ?? '')));
            if ($fresh !== []) {
                file_put_contents($path, json_encode(['count' => count($fresh), 'list' => $fresh]));
                $files[] = $path;
                $kept += count($fresh);
            }
        }

        return new BotResult(BotRunStatus::Succeeded, $files, $kept, $result->reportDate, log: $result->log);
    }
}

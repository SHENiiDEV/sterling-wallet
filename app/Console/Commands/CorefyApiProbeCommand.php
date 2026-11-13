<?php

namespace App\Console\Commands;

use App\Bots\Connectors\ApiFailure;
use App\Bots\Connectors\CorefyApiConnector;
use App\Models\IntegrationAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Asks the Corefy API for a few payments of an account and prints which fields it gives, so the field names the
 * connector reads can be checked against the real API before it is relied on. The key is never printed.
 */
#[Signature('corefy:api-probe {account : Integration account id (bot Corefy export (API))} {--commerce= : Commerce account (default: the first one with a key)} {--hours=24 : How far back to look} {--day= : One day (Y-m-d): how many payments each filter finds for it}')]
#[Description('Check the Corefy API access of a bot account: how many payments it returns and which fields they carry')]
class CorefyApiProbeCommand extends Command
{
    public function handle(CorefyApiConnector $connector): int
    {
        $account = IntegrationAccount::query()->with('provider')->find($this->argument('account'));
        if ($account === null || $account->connector !== $connector->code()) {
            $this->error('Give the id of a bot account whose bot is "Corefy API".');

            return self::FAILURE;
        }

        $commerce = $this->option('commerce') ?: $account->credentials()->orderBy('key')->value('key');
        if (! $commerce) {
            $this->error('This bot account has no API key yet.');

            return self::FAILURE;
        }

        if ($day = $this->option('day')) {
            try {
                $counts = $connector->dayCounts($account, (string) $commerce, (string) $day);
            } catch (ApiFailure $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $this->info("{$commerce} on {$day}:");
            $this->table(['Filter', 'Payments', 'Statuses', 'Created (UTC)', 'Processed (UTC)'], collect($counts)->map(fn ($r, $label) => [$label, $r['count'], $r['statuses'], $r['created'], $r['processed']])->values()->all());

            return self::SUCCESS;
        }

        try {
            $result = $connector->probe($account, (string) $commerce, (int) $this->option('hours'));
        } catch (ApiFailure $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$commerce}: {$result['count']} payment(s) in the last {$this->option('hours')} h (at most 3 are read).");
        if ($result['count'] === 0) {
            $this->warn('Nothing returned: the access works, but there were no payments in this window.');

            return self::SUCCESS;
        }

        $this->line('Fields: '.implode(', ', $result['attributes']));
        $this->line('Payload: '.implode(', ', $result['payload']));
        $this->table(['Field', 'Value'], collect($result['sample'])->map(fn ($v, $k) => [$k, is_scalar($v) ? (string) $v : json_encode($v)])->values()->all());

        $missing = array_diff(['created', 'status', 'amount', 'currency'], $result['attributes']);
        if ($missing !== []) {
            $this->warn('Missing fields the connector needs: '.implode(', ', $missing).' (maybe under another name; tell me which).');
        }

        return self::SUCCESS;
    }
}

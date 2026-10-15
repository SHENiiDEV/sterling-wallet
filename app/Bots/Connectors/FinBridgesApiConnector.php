<?php

namespace App\Bots\Connectors;

use App\Bots\Target;

/**
 * FinBridges C2B wallet. Talks to the public API (no browser): one account is
 * one client wallet. Login = apiKey, Password = apiSecret, settings carry
 * `client_id` and `wallet_id`. The request signing and list endpoint are
 * still to be filled in bots/finbridges-api.mjs.
 */
class FinBridgesApiConnector extends PlaywrightConnector
{
    public function code(): string
    {
        return 'finbridges-api';
    }

    public function label(): string
    {
        return 'FinBridges API (wallet polling)';
    }

    protected function input(Target $target): array
    {
        return [
            'client_id' => $target->account->setting('client_id'),
            'wallet_id' => $target->account->setting('wallet_id'),
        ];
    }
}

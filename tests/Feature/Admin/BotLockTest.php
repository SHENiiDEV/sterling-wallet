<?php

namespace Tests\Feature\Admin;

use App\Jobs\RunConnectorJob;
use App\Models\BotRun;
use Tests\TestCase;

class BotLockTest extends TestCase
{
    public function test_browser_bots_lock_per_account_and_api_bots_per_target()
    {
        $browser = new BotRun(['connector' => 'cardaq-export', 'integration_account_id' => 5, 'target_key' => '2026-11-23']);
        $a = new BotRun(['connector' => 'corefy-api', 'integration_account_id' => 5, 'target_key' => 'range:x:coma_A']);
        $b = new BotRun(['connector' => 'corefy-api', 'integration_account_id' => 5, 'target_key' => 'range:x:coma_B']);

        $this->assertSame('bot-account:5', RunConnectorJob::lockKey($browser, 1));
        $this->assertNotSame(RunConnectorJob::lockKey($a, 2), RunConnectorJob::lockKey($b, 3));
        $this->assertSame(RunConnectorJob::lockKey($a, 2), RunConnectorJob::lockKey($a, 9));
    }
}

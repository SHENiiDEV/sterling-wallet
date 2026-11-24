<?php

namespace Tests\Feature\Admin;

use App\Jobs\RunConnectorJob;
use App\Models\BotRun;
use App\Models\IntegrationAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class CorefyRefreshTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    public function test_the_refresh_queues_yesterday_and_today_for_every_commerce_account_and_not_twice_at_once()
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-11-24 10:00:00');
        $corefy = $this->corefy();
        $mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), $corefy, ['mid' => 'M-1', 'gate_mid' => 'coma_A', 'extra_gate_mids' => ['coma_B']]);
        $account = IntegrationAccount::query()->create(['provider_id' => $corefy->id, 'connector' => 'corefy-api', 'name' => 'Corefy API', 'is_active' => true, 'mid_ids' => [$mid->id]]);

        $this->artisan('corefy:refresh')->assertSuccessful();

        $runs = BotRun::query()->get();
        $this->assertCount(2, $runs); // one per commerce account
        $this->assertSame(['2026-11-23', '2026-11-24'], [$runs[0]->target['from'] ?? null, $runs[0]->target['to'] ?? null]);
        Queue::assertPushed(RunConnectorJob::class, 2);

        // The previous runs are still queued: nothing is doubled up.
        $this->artisan('corefy:refresh')->assertSuccessful();
        $this->assertCount(2, BotRun::query()->get());

        CarbonImmutable::setTestNow();
    }
}

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

    public function test_a_range_given_in_riga_time_still_includes_its_last_day()
    {
        $corefy = $this->corefy();
        $mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), $corefy, ['mid' => 'M-1', 'gate_mid' => 'coma_A']);
        $account = IntegrationAccount::query()->create(['provider_id' => $corefy->id, 'connector' => 'corefy-api', 'name' => 'Corefy API', 'is_active' => true, 'mid_ids' => [$mid->id]]);

        // Riga midnights are 22:00 UTC of the day before: the days must be compared as dates, not as instants.
        $targets = collect(app(\App\Bots\ConnectorRegistry::class)->get('corefy-api')->rangeTargets(
            $account,
            CarbonImmutable::parse('2026-11-25 00:00:00', 'Europe/Riga'),
            CarbonImmutable::parse('2026-11-26 00:00:00', 'Europe/Riga'),
            $account->coveredMids()->get(),
        ))->values();

        $this->assertCount(1, $targets);
        $this->assertSame(['2026-11-25', '2026-11-26'], array_column($targets[0]->params['periods'], 'report_date'));
    }
}

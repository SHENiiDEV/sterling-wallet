<?php

namespace Tests\Feature\Bots;

use App\Bots\BotDispatcher;
use App\Bots\BotSchedule;
use App\Enums\BotRunStatus;
use App\Models\BotRun;
use App\Models\IntegrationAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class BotScheduleTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private function account(array $attributes): IntegrationAccount
    {
        return IntegrationAccount::query()->create([
            'provider_id' => $this->corefy()->id, 'connector' => 'corefy-export', 'name' => 'Corefy', 'username' => 'u', 'password' => 'p', ...$attributes,
        ]);
    }

    private function botRun(IntegrationAccount $account, BotRunStatus $status, string $at): void
    {
        $run = BotRun::query()->create([
            'connector' => 'corefy-export', 'integration_account_id' => $account->id, 'report_date' => '2026-10-28',
            'target_key' => uniqid('k'), 'target' => [], 'status' => $status,
        ]);
        $run->forceFill(['created_at' => CarbonImmutable::parse($at, 'Europe/Riga')])->save();
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("2026-10-29 {$time}", 'Europe/Riga');
    }

    public function test_a_daily_account_starts_from_its_time_and_stops_once_a_run_has_succeeded()
    {
        $schedule = app(BotSchedule::class);
        $account = $this->account(['schedule_mode' => 'daily', 'schedule_at' => '00:05']);

        $this->assertFalse($schedule->due($account, $this->at('00:01')));
        $this->assertTrue($schedule->due($account, $this->at('00:10')));

        // The file was not there yet: tried again an hour later, and so on until it succeeds.
        $this->botRun($account, BotRunStatus::Skipped, '2026-10-29 00:06');
        $this->assertFalse($schedule->due($account, $this->at('00:30')));
        $this->assertTrue($schedule->due($account, $this->at('01:10')));

        $this->botRun($account, BotRunStatus::Succeeded, '2026-10-29 01:11');
        $this->assertFalse($schedule->due($account, $this->at('09:00')));
        $this->assertFalse($schedule->due($account, $this->at('23:59')));

        // The next day starts afresh.
        $this->assertTrue($schedule->due($account, CarbonImmutable::parse('2026-10-30 00:06', 'Europe/Riga')));
    }

    public function test_an_interval_account_waits_its_minutes_after_the_last_run()
    {
        $schedule = app(BotSchedule::class);
        $account = $this->account(['schedule_mode' => 'interval', 'schedule_every_minutes' => 30]);

        $this->assertTrue($schedule->due($account, $this->at('12:00')));

        $this->botRun($account, BotRunStatus::Succeeded, '2026-10-29 12:00');
        $this->assertFalse($schedule->due($account, $this->at('12:20')));
        $this->assertTrue($schedule->due($account, $this->at('12:31')));
    }

    public function test_an_automatic_account_is_left_to_its_connectors_own_schedule()
    {
        $account = $this->account([]);

        $this->assertFalse(BotSchedule::isCustom($account));
        $this->assertFalse(app(BotSchedule::class)->due($account));
        $this->assertSame('Automatic', BotSchedule::label($account));
        $this->assertSame('Daily at 00:05', BotSchedule::label($this->account(['schedule_mode' => 'daily', 'schedule_at' => '00:05'])));
        $this->assertSame('Every hour', BotSchedule::label($this->account(['schedule_mode' => 'interval', 'schedule_every_minutes' => 60])));
    }

    public function test_the_schedule_is_validated_and_saved_with_the_account()
    {
        $admin = User::factory()->superAdmin()->create();
        $base = ['provider_id' => $this->corefy()->id, 'connector' => 'corefy-export', 'name' => 'Corefy', 'username' => 'u', 'password' => 'p'];

        $this->actingAs($admin)->post(route('admin.bots.accounts.store'), [...$base, 'schedule_mode' => 'daily', 'schedule_at' => '25:99'])
            ->assertSessionHasErrors('schedule_at');
        $this->post(route('admin.bots.accounts.store'), [...$base, 'schedule_mode' => 'interval', 'schedule_every_minutes' => 2])
            ->assertSessionHasErrors('schedule_every_minutes');

        $this->post(route('admin.bots.accounts.store'), [...$base, 'schedule_mode' => 'daily', 'schedule_at' => '00:05', 'schedule_every_minutes' => 60])
            ->assertSessionHasNoErrors();

        $account = IntegrationAccount::query()->sole();
        $this->assertSame(['daily', '00:05', null], [$account->schedule_mode, $account->schedule_at, $account->schedule_every_minutes]);
    }

    public function test_custom_accounts_are_only_started_by_the_every_minute_pass()
    {
        Queue::fake();
        $account = $this->account(['schedule_mode' => 'daily', 'schedule_at' => '00:00']);
        $this->mid($this->merchantWithTariff(), $this->cardaq(), $account->provider);

        // The hourly pass of the connector leaves it alone; the every-minute pass starts it (its time is past).
        $this->assertSame([], app(BotDispatcher::class)->dispatchDue('corefy-export'));
        $this->assertNotEmpty(app(BotDispatcher::class)->dispatchDue('corefy-export', true));
    }
}

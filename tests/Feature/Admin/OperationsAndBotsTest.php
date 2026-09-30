<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Jobs\RunConnectorJob;
use App\Models\BotRun;
use App\Models\IntegrationAccount;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Models\User;
use App\Reports\Ingestion\ReportIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class OperationsAndBotsTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        $this->admin = User::factory()->create();
    }

    private function seedOperations(): void
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(), $cardaq, $corefy);
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($cardaq, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));
        $ingestion->ingest($corefy, $this->corefyCsv(), CarbonImmutable::parse('2026-09-15'));
    }

    public function test_operations_screen_filters_and_totals_in_sql()
    {
        $this->seedOperations();

        $this->actingAs($this->admin)->get(route('admin.operations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/operations/index')
                ->where('operations.total', 9)
                ->has('totals', 3)); // EUR × sale, refund, decline

        $this->actingAs($this->admin)->get(route('admin.operations.index', ['search' => '74000000000000000000002']))
            ->assertInertia(fn (Assert $page) => $page->where('operations.total', 1)->where('operations.data.0.payment_id', 'CQ-2'));

        $this->actingAs($this->admin)->get(route('admin.operations.index', ['search' => '411111']))
            ->assertInertia(fn (Assert $page) => $page->where('operations.total', 3));

        $this->actingAs($this->admin)->get(route('admin.operations.index', ['role' => 'gate', 'type' => 'decline']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('operations.total', 2)
                ->where('totals.0.operations', 2)
                ->where('totals.0.amount', '95.00'));

        // After generation, the three sales are paired; the refund (and nothing else) is left without a pair.
        $this->actingAs($this->admin)->get(route('admin.operations.index', ['unmatched' => 1]))
            ->assertInertia(fn (Assert $page) => $page->where('operations.total', 1)->where('operations.data.0.payment_id', 'CQ-4'));
    }

    public function test_operation_card_shows_source_row_and_pair()
    {
        $this->seedOperations();
        $sale = MerchantOperation::query()->where('payment_id', 'CQ-1')->sole();

        $this->actingAs($this->admin)->get(route('admin.operations.show', $sale))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/operations/show')
                ->where('operation.sp_id', 'coma_TEST1_pi_1')
                ->where('pair.payment_id', 'coma_TEST1_pi_1')
                ->where('pair.role', 'gate')
                ->where('raw.Transaction ID', 'CQ-1')
                ->where('charges.merchant_percent', '3')
                ->where('charges.merchant_fee', '3.00')
                ->where('report.status', 'completed'));
    }

    public function test_dashboard_warns_about_unknown_mids_and_blocked_reports()
    {
        app(ReportIngestionService::class)->ingest($this->cardaq(), $this->cardaqCsv('4499999999'), CarbonImmutable::parse('2026-09-15'));

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reportAlerts.0.key', 'review_mids')
                ->where('reportAlerts.0.detail', '4499999999')
                ->where('reportAlerts.1.key', 'blocked')
                ->where('reportAlerts.1.count', 1));
    }

    public function test_merchant_users_cannot_open_operations_or_bots()
    {
        $merchant = User::factory()->create(['role' => UserRole::Merchant]);

        $this->actingAs($merchant)->get(route('admin.operations.index'))->assertForbidden();
        $this->actingAs($merchant)->get(route('admin.bots.index'))->assertForbidden();
    }

    public function test_bot_accounts_keep_secrets_write_only()
    {
        $corefy = $this->corefy();

        $this->actingAs($this->admin)->post(route('admin.bots.accounts.store'), [
            'provider_id' => $corefy->id,
            'connector' => 'corefy-export',
            'name' => 'Corefy main',
            'username' => 'bot@example.test',
            'password' => 'hunter2',
            'settings' => '{"proxy": "http://proxy:8888"}',
            'is_active' => true,
        ])->assertRedirect(route('admin.bots.index'));

        $account = IntegrationAccount::query()->sole();
        $this->assertSame(['proxy' => 'http://proxy:8888'], $account->settings);

        // Editing without secrets keeps them.
        $this->actingAs($this->admin)->put(route('admin.bots.accounts.update', $account), [
            'provider_id' => $corefy->id,
            'connector' => 'corefy-export',
            'name' => 'Corefy renamed',
            'username' => '',
            'password' => '',
            'is_active' => true,
        ])->assertRedirect();

        $account->refresh();
        $this->assertSame(['Corefy renamed', 'bot@example.test', 'hunter2'], [$account->name, $account->username, $account->password]);

        $this->actingAs($this->admin)->get(route('admin.bots.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/bots/index')
                ->where('accounts.0.has_password', true)
                ->missing('accounts.0.password'));

        $this->actingAs($this->admin)->post(route('admin.bots.accounts.store'), [
            'provider_id' => $corefy->id, 'connector' => 'corefy-export', 'name' => 'Broken', 'username' => 'u', 'password' => 'p',
            'settings' => '{not json',
        ])->assertSessionHasErrors('settings');
    }

    public function test_run_now_and_retry_queue_runs()
    {
        Queue::fake();
        $corefy = $this->corefy();
        $this->mid($this->merchantWithTariff(), $this->cardaq(), $corefy);
        $account = IntegrationAccount::query()->create([
            'provider_id' => $corefy->id, 'connector' => 'corefy-export', 'name' => 'Corefy', 'username' => 'u', 'password' => 'p',
        ]);

        $this->actingAs($this->admin)->post(route('admin.bots.accounts.run', $account), ['report_date' => '2026-09-12'])
            ->assertRedirect();

        $run = BotRun::query()->sole();
        $this->assertSame(['2026-09-13', '2026-09-13:coma_TEST1'], [$run->report_date->toDateString(), $run->target_key]);
        $this->assertSame($this->admin->id, $run->triggered_by);
        Queue::assertPushed(RunConnectorJob::class, 1);

        // Still queued → retry doesn't double it.
        $this->actingAs($this->admin)->post(route('admin.bots.runs.retry', $run))->assertRedirect();
        $this->assertSame(1, BotRun::query()->count());

        $run->update(['status' => 'failed']);
        $this->actingAs($this->admin)->post(route('admin.bots.runs.retry', $run))->assertRedirect();
        $this->assertSame(2, BotRun::query()->count());
    }

    public function test_screenshots_are_served_only_from_storage()
    {
        $run = BotRun::query()->create([
            'connector' => 'corefy-export', 'report_date' => '2026-09-15', 'target_key' => 'x', 'status' => 'failed',
            'screenshot_path' => '/etc/passwd',
        ]);

        $this->actingAs($this->admin)->get(route('admin.bots.runs.screenshot', $run))->assertNotFound();

        $path = Storage::disk('local')->path('bots/test-shot.png');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, 'png');
        $run->update(['screenshot_path' => $path]);

        $this->actingAs($this->admin)->get(route('admin.bots.runs.screenshot', $run))->assertOk();
        @unlink($path);
    }

    public function test_provider_form_saves_report_settings()
    {
        $this->actingAs($this->admin)->post(route('admin.providers.store'), [
            ...Provider::factory()->make(['code' => 'madfin', 'name' => 'Madfin'])->only([
                'name', 'code', 'type', 'cost_acq_eu_percent', 'cost_acq_non_eu_percent',
            ]),
            'type' => 'bank',
            'is_active' => true,
            'cost_success_fixed' => 0, 'cost_decline_fixed' => 0, 'cost_refund_fixed' => 0, 'cost_chargeback_fixed' => 0,
            'cost_crypto_percent' => 0, 'settlement_fee' => 0, 'min_settlement' => 0, 'rolling_reserve_percent' => 0,
            'rolling_reserve_days' => 180, 'rolling_reserve_cap' => 0,
            'report_format' => 'madfin',
            'connector' => 'madfin-export',
            'timezone' => 'Europe/Riga',
            'report_delay_days' => 1,
            'matching' => '{"window_minutes": 10, "keys": [["card_bin", "amount", "currency"]]}',
        ])->assertRedirect(route('admin.providers.index'));

        $provider = Provider::query()->where('code', 'madfin')->sole();
        $this->assertSame(['madfin', 'madfin-export', 1], [$provider->report_format, $provider->connector, $provider->report_delay_days]);
        $this->assertSame(10, $provider->matchingRules()['window_minutes']);
        $this->assertTrue($provider->matchingRules()['try_timezone_shift']); // default kept

        $this->actingAs($this->admin)->put(route('admin.providers.update', $provider), [
            ...$provider->only(['name', 'code', 'report_format', 'connector', 'timezone', 'report_delay_days']),
            'type' => 'bank', 'cost_acq_eu_percent' => 1, 'cost_acq_non_eu_percent' => 1,
            'cost_success_fixed' => 0, 'cost_decline_fixed' => 0, 'cost_refund_fixed' => 0, 'cost_chargeback_fixed' => 0,
            'cost_crypto_percent' => 0, 'settlement_fee' => 0, 'min_settlement' => 0, 'rolling_reserve_percent' => 0,
            'rolling_reserve_days' => 180, 'rolling_reserve_cap' => 0,
            'matching' => '{"keys": [["iban"]]}',
        ])->assertSessionHasErrors('matching.keys.0.0');
    }
}

<?php

namespace Tests\Feature\Reports;

use App\Enums\ReportStatus;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use App\Models\MerchantOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SimulateReportsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        $this->travelTo('2026-09-17 10:00:00');
    }

    public function test_it_builds_test_merchants_and_complete_reports_then_cleans_up()
    {
        $real = Merchant::factory()->create(['name' => 'Real merchant']);

        $this->artisan('reports:simulate', ['--days' => 3, '--per-day' => 10, '--direct' => true, '--seed' => 7])->assertSuccessful();

        $this->assertSame(2, Merchant::query()->where('is_test', true)->count());
        // 14–16 Sep (Mon–Wed): 3 report days × 3 MIDs, every one with both sources.
        $this->assertSame(9, DailyReportTask::query()->count());
        $this->assertSame(9, DailyReportTask::query()->where('status', ReportStatus::Completed)->count());
        $this->assertGreaterThan(0, MerchantOperation::query()->where('role', 'bank')->whereNotNull('matched_operation_id')->count());
        $this->assertGreaterThan(0, MerchantOperation::query()->where('operation_type', 'decline')->count());

        // Same seed again: nothing duplicated.
        $ops = MerchantOperation::query()->count();
        $this->artisan('reports:simulate', ['--days' => 3, '--per-day' => 10, '--direct' => true, '--seed' => 7])->assertSuccessful();
        $this->assertSame($ops, MerchantOperation::query()->count());

        $this->artisan('reports:simulate', ['--cleanup' => true])->assertSuccessful();
        $this->assertSame(0, MerchantOperation::query()->count());
        $this->assertSame(0, DailyReportTask::query()->count());
        $this->assertSame(['Real merchant'], Merchant::query()->pluck('name')->all());
        $this->assertNotNull($real->fresh());
    }

    public function test_by_default_files_go_through_the_bot_api()
    {
        config(['sterling.bot_api_key' => 'k', 'app.url' => 'https://wallet.test']);
        Http::fake(['wallet.test/*' => Http::response(['status' => 'ok'])]);

        $this->artisan('reports:simulate', ['--days' => 1, '--per-day' => 5, '--seed' => 1])->assertSuccessful();

        Http::assertSentCount(6); // 3 MIDs × (acquirer + gateway file)
        Http::assertSent(fn ($r) => $r->url() === 'https://wallet.test/api/v1/reports/upload/corefy' && $r->hasHeader('Authorization', 'Bearer k'));
        Http::assertSent(fn ($r) => $r->url() === 'https://wallet.test/api/v1/reports/upload/madfin');
    }

    public function test_it_refuses_http_mode_without_an_api_key()
    {
        config(['sterling.bot_api_key' => null]);

        $this->artisan('reports:simulate')->assertFailed();
    }
}

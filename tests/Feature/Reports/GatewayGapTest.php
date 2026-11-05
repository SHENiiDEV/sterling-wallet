<?php

namespace Tests\Feature\Reports;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\DailyReportTask;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\User;
use App\Reports\Generation\DailyReportGenerator;
use App\Reports\Ingestion\ReportIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class GatewayGapTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private MerchantMid $mid;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        Http::fake();
        config(['sterling.bots.telegram_token' => 'tg', 'sterling.bots.telegram_chat_id' => '42']);

        $this->mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), $this->corefy());
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($this->mid->bankProvider, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));
        $ingestion->ingest($this->mid->gateProvider, $this->corefyCsv(), CarbonImmutable::parse('2026-09-15'));
    }

    private function task(): DailyReportTask
    {
        return DailyReportTask::query()->where('report_date', '2026-09-15')->sole();
    }

    private function extraGateSales(int $count): void
    {
        foreach (range(1, $count) as $i) {
            MerchantOperation::query()->create([
                'merchant_id' => $this->mid->merchant_id, 'merchant_mid_id' => $this->mid->id, 'provider_id' => $this->mid->gate_provider_id,
                'role' => ProviderType::Gate, 'payment_id' => "cpi_EXTRA{$i}", 'operation_type' => OperationType::Sale, 'amount' => '100.00',
                'currency' => 'EUR', 'card_bin' => '400000', 'card_last4' => sprintf('%04d', $i), 'report_date' => '2026-09-15',
                'transaction_at' => CarbonImmutable::parse('2026-09-15 15:00:00', 'UTC'),
            ]);
        }
    }

    public function test_equal_sides_are_ok_and_nothing_is_announced()
    {
        $gap = $this->task()->summary_data['gateway_check'];

        $this->assertSame([3, 3, 'none', 'ok'], [$gap['gate_sales'], $gap['bank_sales'], $gap['direction'], $gap['level']]);
        Http::assertNothingSent();
    }

    public function test_a_big_gap_is_flagged_announced_once_and_shown_on_the_dashboard()
    {
        // The dashboard lists the last 30 days.
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00', 'Europe/Riga'));
        $this->extraGateSales(3); // the gateway took 3 more payments (300) than the bank completed

        app(DailyReportGenerator::class)->generate($this->task());

        $gap = $this->task()->summary_data['gateway_check'];
        $this->assertSame([6, 3, 3, '300.00', 'gateway_more', 'high', true], [$gap['gate_sales'], $gap['bank_sales'], $gap['diff_count'], $gap['diff_amount'], $gap['direction'], $gap['level'], $gap['notified']]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage') && str_contains($request['text'], 'Gateway and acquirer disagree'));

        // Regenerating does not announce the same gap again.
        app(DailyReportGenerator::class)->generate($this->task());
        Http::assertSentCount(1);

        $admin = User::factory()->superAdmin()->create();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('reportAlerts', fn ($alerts) => collect($alerts)->contains(fn ($a) => $a['key'] === 'gateway_gap' && $a['count'] === 1)));
    }

    public function test_a_small_gap_is_not_flagged()
    {
        $this->extraGateSales(1);

        app(DailyReportGenerator::class)->generate($this->task());

        $this->assertSame('ok', $this->task()->summary_data['gateway_check']['level']);
        Http::assertNothingSent();
    }
}

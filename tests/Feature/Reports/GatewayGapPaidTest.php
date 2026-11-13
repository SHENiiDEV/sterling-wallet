<?php

namespace Tests\Feature\Reports;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\DailyReportTask;
use App\Models\FxRate;
use App\Models\MerchantOperation;
use App\Models\User;
use App\Reports\Generation\DailyReportGenerator;
use App\Reports\Ingestion\ReportIngestionService;
use App\Settlements\SettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class GatewayGapPaidTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    public function test_a_gap_stays_on_the_dashboard_until_the_report_is_paid()
    {
        Storage::fake('local');
        Mail::fake();
        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00', 'Europe/Riga'));
        $admin = User::factory()->superAdmin()->create();
        $merchant = $this->merchantWithTariff(['crypto_provider_id' => $this->oxen()->id]);
        $mid = $this->mid($merchant, $this->cardaq(), $this->corefy());
        $merchant->wallets()->create(['type' => 'provider_inflow', 'currency' => 'USDC', 'network' => 'TRC20', 'address' => 'TXabc']);
        FxRate::query()->create(['rate_date' => '2026-09-01', 'base' => 'EUR', 'quote' => 'USDC', 'rate' => '1.10']);
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($mid->bankProvider, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));
        $ingestion->ingest($mid->gateProvider, $this->corefyCsv(), CarbonImmutable::parse('2026-09-15'));
        foreach (range(1, 3) as $i) {
            MerchantOperation::query()->create([
                'merchant_id' => $mid->merchant_id, 'merchant_mid_id' => $mid->id, 'provider_id' => $mid->gate_provider_id, 'role' => ProviderType::Gate,
                'payment_id' => "cpi_EXTRA{$i}", 'operation_type' => OperationType::Sale, 'amount' => '100.00', 'currency' => 'EUR', 'card_bin' => '400000',
                'card_last4' => sprintf('%04d', $i), 'report_date' => '2026-09-15', 'transaction_at' => CarbonImmutable::parse('2026-09-15 15:00:00', 'UTC'),
            ]);
        }
        app(DailyReportGenerator::class)->generate(DailyReportTask::query()->where('report_date', '2026-09-15')->sole());

        $count = function () use ($admin): int {
            $found = 0;
            $this->actingAs($admin)->get(route('admin.dashboard'))->assertInertia(function (Assert $page) use (&$found) {
                $page->where('reportAlerts', function ($alerts) use (&$found) {
                    $found = (int) (collect($alerts)->firstWhere('key', 'gateway_gap')['count'] ?? 0);

                    return true;
                });
            });

            return $found;
        };
        $this->assertSame(1, $count());

        // In a draft it can still change, so it stays; once the settlement is approved or paid it is off the list.
        $settlement = app(SettlementService::class)->createDraft($merchant, $admin);
        $this->assertSame(1, $count());
        app(SettlementService::class)->recordPaid($settlement, $admin, CarbonImmutable::parse('2026-09-18'));
        $this->assertSame(0, $count());
    }
}

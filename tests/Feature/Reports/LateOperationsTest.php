<?php

namespace Tests\Feature\Reports;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\DailyReportTask;
use App\Models\FxRate;
use App\Models\MerchantOperation;
use App\Models\User;
use App\Reports\Ingestion\ReportIngestionService;
use App\Settlements\SettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class LateOperationsTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    public function test_an_operation_that_came_after_a_paid_report_was_built_is_listed()
    {
        Storage::fake('local');
        Mail::fake();
        $this->travelTo('2026-09-20 10:00:00');
        $admin = User::factory()->superAdmin()->create();
        $merchant = $this->merchantWithTariff(['crypto_provider_id' => $this->oxen()->id]);
        $mid = $this->mid($merchant, $this->cardaq(), $this->corefy(), ['mid' => '4400000001']);
        $merchant->wallets()->create(['type' => 'provider_inflow', 'currency' => 'USDC', 'network' => 'TRC20', 'address' => 'TXabc']);
        FxRate::query()->create(['rate_date' => '2026-09-01', 'base' => 'EUR', 'quote' => 'USDC', 'rate' => '1.10']);
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($mid->bankProvider, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));
        $ingestion->ingest($mid->gateProvider, $this->corefyCsv(), CarbonImmutable::parse('2026-09-15'));

        // Nothing wrong yet, and a report that is not in a settlement is not looked at.
        $this->assertSame(0, Artisan::call('reports:late-operations'));
        $this->assertStringContainsString('0 report(s) in a settlement checked', Artisan::output());

        $draft = app(SettlementService::class)->createDraft($merchant, $admin);
        app(SettlementService::class)->recordPaid($draft, $admin, CarbonImmutable::parse('2026-09-18'));
        $this->assertStringContainsString('agrees', $this->late());

        // The acquirer sends one more payment of that day, hours after the report was paid.
        $this->travelTo('2026-09-21 10:00:00');
        MerchantOperation::query()->create([
            'merchant_id' => $mid->merchant_id, 'merchant_mid_id' => $mid->id, 'provider_id' => $mid->bank_provider_id, 'role' => ProviderType::Bank,
            'payment_id' => 'CQ-LATE', 'operation_type' => OperationType::Sale, 'amount' => '54.67', 'currency' => 'EUR', 'card_bin' => '524886', 'card_last4' => '6868',
            'report_date' => '2026-09-15', 'transaction_at' => CarbonImmutable::parse('2026-09-15 21:59:00', 'UTC'),
        ]);

        $output = $this->late();
        $this->assertStringContainsString('CQ-LATE', $output);
        $this->assertStringContainsString('54.67', $output);
        $this->assertStringContainsString('3 → 4', $output);
        $this->assertSame(1, DailyReportTask::query()->where('report_date', '2026-09-15')->count());
    }

    private function late(): string
    {
        Artisan::call('reports:late-operations');

        return Artisan::output();
    }
}

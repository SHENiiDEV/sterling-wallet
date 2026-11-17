<?php

namespace Tests\Feature\Settlements;

use App\Models\FxRate;
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

class SettlementRefundsTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    public function test_the_refunds_behind_a_settlement_statement_are_listed_with_their_ids()
    {
        Storage::fake('local');
        Mail::fake();
        $admin = User::factory()->superAdmin()->create();
        $merchant = $this->merchantWithTariff(['crypto_provider_id' => $this->oxen()->id]);
        $mid = $this->mid($merchant, $this->cardaq(), $this->corefy());
        $merchant->wallets()->create(['type' => 'provider_inflow', 'currency' => 'USDC', 'network' => 'TRC20', 'address' => 'TXabc']);
        FxRate::query()->create(['rate_date' => '2026-09-01', 'base' => 'EUR', 'quote' => 'USDC', 'rate' => '1.10']);
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($mid->bankProvider, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));
        $ingestion->ingest($mid->gateProvider, $this->corefyCsv(), CarbonImmutable::parse('2026-09-15'));
        $settlement = app(SettlementService::class)->createDraft($merchant, $admin);

        $exit = Artisan::call('settlement:refunds', ['number' => $settlement->number]);
        $output = Artisan::output();

        // The fixture's acquirer file has a refund of 30.00 (CQ-4).
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('CQ-4', $output, $output);
        $this->assertStringContainsString('30.00', $output, $output);
        $this->assertStringContainsString('refund', $output, $output);

        $this->assertSame(1, Artisan::call('settlement:refunds', ['number' => 'SET-0000-000000']));
    }
}

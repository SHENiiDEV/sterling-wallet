<?php

namespace Tests\Feature\Reports;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\MerchantOperation;
use App\Reports\Ingestion\ReportIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class GatewayGapCommandTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    public function test_it_lists_the_gateway_sales_the_acquirer_did_not_complete()
    {
        Storage::fake('local');
        Mail::fake();
        $mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), $this->corefy());
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($mid->bankProvider, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));
        $ingestion->ingest($mid->gateProvider, $this->corefyCsv(), CarbonImmutable::parse('2026-09-15'));

        $run = function () use ($mid) {
            $exit = Artisan::call('reports:gateway-gap', ['--mid' => $mid->mid, '--from' => '2026-09-15', '--to' => '2026-09-15']);

            return [$exit, Artisan::output()];
        };
        [$exit, $output] = $run();
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('has an acquirer sale in the same period', $output);

        // A payment the gateway took and the acquirer never reported.
        MerchantOperation::query()->create([
            'merchant_id' => $mid->merchant_id, 'merchant_mid_id' => $mid->id, 'provider_id' => $mid->gate_provider_id, 'role' => ProviderType::Gate,
            'payment_id' => 'cpi_LOST', 'operation_type' => OperationType::Sale, 'amount' => '47.10', 'currency' => 'EUR', 'card_bin' => '535617', 'card_last4' => '8893',
            'report_date' => '2026-09-15', 'transaction_at' => CarbonImmutable::parse('2026-09-15 20:22:00', 'UTC'),
        ]);

        [, $output] = $run();
        $this->assertStringContainsString('1 gateway sale(s) without an acquirer sale', $output);
        $this->assertStringContainsString('cpi_LOST', $output);
        $this->assertStringContainsString('47.10', $output);
        $this->assertStringContainsString('no pair', $output);

        $this->assertSame(1, Artisan::call('reports:gateway-gap', ['--mid' => 'nope']));
    }
}

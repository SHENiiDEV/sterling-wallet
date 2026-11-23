<?php

namespace Tests\Feature\Reports;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\DailyReportTask;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Reports\Ingestion\ReportIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class SharedTerminalTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private MerchantMid $a;

    private MerchantMid $b;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00:00', 'Europe/Riga'));
        $bank = $this->cardaq();
        $gate = $this->corefy();
        // One bank terminal, 4400000001, given to two merchants; each has its own commerce account at the gateway.
        $this->a = $this->mid($this->merchantWithTariff(['name' => 'Client A']), $bank, $gate, ['mid' => '4400000001', 'gate_mid' => 'coma_A']);
        $this->b = $this->mid($this->merchantWithTariff(['name' => 'Client B']), $bank, $gate, ['mid' => '4400000001-B', 'bank_mid' => '4400000001', 'gate_mid' => 'coma_B']);
    }

    private function gateFile(string $account, array $payments): UploadedFile
    {
        return $this->csvFile([
            ['ID', 'Commerce account', 'Status', 'Resolution', 'Amount', 'Currency', 'Card', 'Customer email', 'Created'],
            ...array_map(fn (array $p) => ["{$account}_{$p[0]}", $account, 'processed', 'ok', $p[1], 'EUR', $p[2], 'x@buyer.test', "2026-09-15 {$p[3]}"], $payments),
        ], "{$account}.csv", ';');
    }

    private function day(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-15');
    }

    public function test_the_banks_rows_of_a_shared_terminal_go_to_the_merchant_of_the_gateway_payment_they_pair_with()
    {
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($this->a->gateProvider, $this->gateFile('coma_A', [['pi_1', '100.00', '411111******1111', '10:02:00'], ['pi_2', '200.00', '555555******4444', '11:01:00']]), $this->day());
        $ingestion->ingest($this->b->gateProvider, $this->gateFile('coma_B', [['pi_3', '50.00', '422222******2222', '12:03:00']]), $this->day());

        // The bank's file has the terminal's number only: three sales and a refund of 30.00 that gives nothing back.
        $ingestion->ingest($this->a->bankProvider, $this->cardaqCsv(), $this->day());

        $sale = fn (string $id) => MerchantOperation::query()->where('role', ProviderType::Bank)->where('payment_id', $id)->sole();
        $this->assertSame([$this->a->id, false], [$sale('CQ-1')->merchant_mid_id, $sale('CQ-1')->unassigned]);
        $this->assertSame([$this->a->id, false], [$sale('CQ-2')->merchant_mid_id, $sale('CQ-2')->unassigned]);
        $this->assertSame([$this->b->id, false], [$sale('CQ-3')->merchant_mid_id, $sale('CQ-3')->unassigned]);

        // The refund pairs with no payment and finds no sale of 30.00: it waits, belonging to nobody.
        $refund = MerchantOperation::query()->where('role', ProviderType::Bank)->where('operation_type', OperationType::Refund)->sole();
        $this->assertTrue($refund->unassigned);

        // Each merchant's report carries its own sales only, and the one that has waiting rows says so.
        $reportA = DailyReportTask::query()->where('merchant_mid_id', $this->a->id)->where('report_date', '2026-09-15')->sole();
        $reportB = DailyReportTask::query()->where('merchant_mid_id', $this->b->id)->where('report_date', '2026-09-15')->sole();
        $this->assertSame([2, 1], [$reportA->sales_count, $reportB->sales_count]);
        $this->assertEqualsWithDelta(300.0, (float) $reportA->getRawOriginal('turnover'), 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $reportB->getRawOriginal('turnover'), 0.001);
        $this->assertSame(1, $reportA->summary_data['terminal']['unassigned']);
        $this->assertEqualsWithDelta(0.0, (float) $reportA->getRawOriginal('refunds_amount'), 0.001);

        // Loading the same bank file again changes nothing about whose a row is.
        $ingestion->ingest($this->a->bankProvider, $this->cardaqCsv(), $this->day());
        $this->assertSame($this->b->id, $sale('CQ-3')->merchant_mid_id);
        $this->assertSame(4, MerchantOperation::query()->where('role', ProviderType::Bank)->count());
    }

    public function test_a_refund_goes_to_the_merchant_whose_sale_it_gives_back()
    {
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($this->a->gateProvider, $this->gateFile('coma_A', [['pi_1', '100.00', '411111******1111', '10:02:00']]), $this->day());
        $ingestion->ingest($this->b->gateProvider, $this->gateFile('coma_B', [['pi_3', '30.00', '411111******1111', '12:03:00']]), $this->day());
        $ingestion->ingest($this->a->bankProvider, $this->csvFile([
            ['Merchant name', 'MID', 'Transaction ID', 'ARN', 'Card number', 'Card brand', 'Region', 'Trn type', 'Amount', 'Currency', 'Trn date'],
            ['SHOP LTD', '4400000001', 'CQ-1', '740000000000000000000001', '411111******1111', 'VISA', 'EU', '5', '100.00', 'EUR', '2026-09-15 10:00:00'],
            ['SHOP LTD', '4400000001', 'CQ-9', '740000000000000000000009', '411111******1111', 'VISA', 'EU', '5', '30.00', 'EUR', '2026-09-15 12:00:00'],
            ['SHOP LTD', '4400000001', 'CQ-R', '740000000000000000000010', '411111******1111', 'VISA', 'EU', '25', '30.00', 'EUR', '2026-09-15 15:00:00'],
        ], 'bank.csv'), $this->day());

        // The sale of 30.00 on the same card was paid through client B's account: so is the refund of it.
        $refund = MerchantOperation::query()->where('role', ProviderType::Bank)->where('operation_type', OperationType::Refund)->sole();
        $this->assertSame([$this->b->id, false], [$refund->merchant_mid_id, $refund->unassigned]);
        $reportB = DailyReportTask::query()->where('merchant_mid_id', $this->b->id)->where('report_date', '2026-09-15')->sole();
        $this->assertEqualsWithDelta(30.0, (float) $reportB->getRawOriginal('refunds_amount'), 0.001);
    }
}

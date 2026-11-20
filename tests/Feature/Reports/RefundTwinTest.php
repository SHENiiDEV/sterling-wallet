<?php

namespace Tests\Feature\Reports;

use App\Enums\OperationType;
use App\Models\MerchantOperation;
use App\Reports\Ingestion\ReportIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class RefundTwinTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private function file(string $trnType, string $arn, string $time): UploadedFile
    {
        return $this->csvFile([
            ['Merchant name', 'MID', 'Transaction ID', 'ARN', 'RRN', 'Card number', 'Card brand', 'Region', 'Trn type', 'Amount', 'Currency', 'Trn date'],
            ['SHOP LTD', '4400000001', 'P-REFUND', $arn, '631512400424', '411111******1111', 'VISA', 'EU', $trnType, '-285.01', 'EUR', $time],
        ], 'refund.csv');
    }

    public function test_the_same_refund_in_two_layouts_is_one_operation_and_keeps_the_fuller_record()
    {
        Storage::fake('local');
        Mail::fake();
        $mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), $this->corefy());
        $ingestion = app(ReportIngestionService::class);
        $day = CarbonImmutable::parse('2026-09-15');

        // First the poorer record (no type), then the clearing file's record: same payment, RRN and amount, other ARN.
        $ingestion->ingest($mid->bankProvider, $this->file('', 'ARN-OF-THE-SALE', '2026-09-15'), $day);
        $ingestion->ingest($mid->bankProvider, $this->file('6', 'ARN-OF-THE-REFUND', '2026-09-15 12:31:35'), $day);
        // And the clearing file again: nothing new.
        $ingestion->ingest($mid->bankProvider, $this->file('6', 'ARN-OF-THE-REFUND', '2026-09-15 12:31:35'), $day);

        $refunds = MerchantOperation::query()->where('merchant_mid_id', $mid->id)->where('operation_type', OperationType::Refund)->get();
        $this->assertCount(1, $refunds);
        $this->assertSame('6', $refunds->first()->trn_type);
        $this->assertNotNull($refunds->first()->transaction_at);
    }

    public function test_a_late_file_with_a_payment_that_is_already_there_does_not_count_it_twice()
    {
        Storage::fake('local');
        Mail::fake();
        $mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), $this->corefy());
        $ingestion = app(ReportIngestionService::class);
        $day = CarbonImmutable::parse('2026-09-15');
        $sale = fn (string $trnType, string $time) => $this->csvFile([
            ['Merchant name', 'MID', 'Transaction ID', 'ARN', 'Card number', 'Card brand', 'Region', 'Trn type', 'Amount', 'Currency', 'Trn date'],
            ['SHOP LTD', '4400000001', 'P-SALE', 'ARN-1', '411111******1111', 'VISA', 'EU', $trnType, '1047.50', 'EUR', $time],
        ], 'sale.csv');

        // The payment, and later the same payment again in another layout and an hour earlier on the clock.
        $ingestion->ingest($mid->bankProvider, $sale('5', '2026-09-15 10:28:00'), $day);
        $ingestion->ingest($mid->bankProvider, $sale('', '2026-09-15 09:28:00'), $day);

        $sales = MerchantOperation::query()->where('merchant_mid_id', $mid->id)->where('operation_type', OperationType::Sale)->where('payment_id', 'P-SALE')->get();
        $this->assertCount(1, $sales);
        $this->assertSame('5', $sales->first()->trn_type);
    }
}

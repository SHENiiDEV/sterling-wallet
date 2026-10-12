<?php

namespace Tests\Feature\Reports;

use App\Enums\OperationType;
use App\Models\MerchantOperation;
use App\Reports\Parsers\OperationRow;
use App\Reports\Parsers\ParserRegistry;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class ParserTest extends TestCase
{
    use BuildsReportFixtures;

    /**
     * @return list<OperationRow>
     */
    private function parse(string $format, UploadedFile $file): array
    {
        return iterator_to_array(app(ParserRegistry::class)->forFormat($format)->rows($file, 'Europe/Riga'), false);
    }

    public function test_cardaq_xlsx_with_title_block_and_processing_dates()
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['EXORAPAY FINANCE LTD — clearing report'],
            ['Processing date(s)', '2026.09.11-2026.09.13'],
            [],
            ['Merchant name', 'MID', 'Transaction ID', 'ARN', 'Card number', 'Card brand', 'Region', 'Trn type', 'Amount', 'Currency', 'Trn date', 'EU fee'],
            ['SHOP LTD', '4400000001', 'CQ-1', 'ARN1', '411111******1111', 'VISA', 'EU', '5', 100.1, 'EUR', '2026-09-12 10:00:00', 1.5],
            ['SHOP LTD', '4400000001', 'CQ-2', 'ARN2', '555555******4444', 'MC', 'NON EU', '25', 20, 'EUR', '2026-09-12 11:00:00', null],
            ['Total', null, null, null, null, null, null, null, null, null, null, null],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'cq').'.xlsx';
        (new Xlsx($book))->save($path);
        $file = new UploadedFile($path, 'cardaq.xlsx', null, null, true);

        $parser = app(ParserRegistry::class)->forFormat('cardaq');
        $this->assertSame('2026-09-13', $parser->reportDate($file)?->toDateString());

        $rows = $this->parse('cardaq', $file);
        $this->assertCount(2, $rows);
        [$sale, $refund] = $rows;

        $this->assertSame('4400000001', $sale->mid);
        $this->assertSame('100.1', $sale->amount);
        $this->assertSame(['411111', '1111', 'visa', 'eu'], [$sale->cardBin, $sale->cardLast4, $sale->ips, $sale->region]);
        $this->assertSame('2026-09-12 07:00:00', $sale->transactedAt?->toDateTimeString());
        $this->assertSame('1.5', $sale->fees['eu_fee']);
        $this->assertSame('SHOP LTD', $sale->raw['Merchant name']);

        $this->assertSame(['mastercard', 'non_eu', '25'], [$refund->ips, $refund->region, $refund->trnType]);
    }

    public function test_corefy_csv_maps_status_and_drops_unfinished_invoices()
    {
        $rows = $this->parse('corefy', $this->corefyCsv());

        $this->assertCount(5, $rows); // the "processing" invoice is not an operation yet
        $this->assertSame(['coma_TEST1_pi_1', 'coma_TEST1', 'processed', 'ok', 'a@buyer.test'], [
            $rows[0]->paymentId, $rows[0]->mid, $rows[0]->processingCode, $rows[0]->resolution, $rows[0]->email,
        ]);
        $this->assertSame('visa', $rows[0]->ips); // from BIN, the export has no brand column
        $this->assertSame(['process_failed', 'insufficient_funds'], [$rows[3]->processingCode, $rows[3]->resolution]);
    }

    public function test_madfin_uses_its_own_column_names_and_decimal_commas()
    {
        $rows = $this->parse('madfin', $this->madfinCsv());

        $this->assertCount(4, $rows);
        $this->assertSame(['5500000001', 'MF-1', '100.00', 'visa', 'eu'], [$rows[0]->mid, $rows[0]->paymentId, $rows[0]->amount, $rows[0]->ips, $rows[0]->region]);
        $this->assertSame('Refund', $rows[3]->trnType);
    }

    public function test_madfin_workbook_reads_the_transactions_sheet_and_its_columns()
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Main')->fromArray([['Settlement statement for:', 'LUNEHOM LTD'], ['Transaction Volume', 88]]);
        $header = ['created', 'updated', 'id', 'type', 'state', 'error_code', 'method', 'amount_proc_curr', 'proc_curr', 'merchant_name', 'shop_name', 'customer_account_number', 'card_country_code', 'region', 'reference_id', 'bank_date', 'card_brand'];
        $book->createSheet()->setTitle('Transactions')->fromArray([
            $header,
            [46301.486111111, 46301.486805556, 'aaa1', 'DEPOSIT', 'DECLINED', 7, 'BASIC_CARD', 5.02, 'EUR', 'LUNEHOM LTD', '1792-1_CC_IN', '535456***1378', 'LV', 'Intraregion', 'pay_one', 46301, 'MASTERCARD'],
            [46303.443055556, 46303.443055556, 'aaa2', 'DEPOSIT', 'COMPLETED', null, 'APPLE_PAY', 179, 'EUR', 'LUNEHOM LTD', '1792-1_CC_IN', '440066***9874', 'US', 'Interregion', 'pay_two', 46303, 'VISA'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'mf').'.xlsx';
        (new Xlsx($book))->save($path);

        $rows = $this->parse('madfin', new UploadedFile($path, 'LUNEHOM_20261006-20261008.xlsx', null, null, true));

        $this->assertCount(2, $rows);
        $this->assertSame(['1792-1_CC_IN', 'aaa1', '5.02', 'EUR', 'mastercard', 'eu', 'LV', 'DECLINED', null], [
            $rows[0]->mid, $rows[0]->paymentId, $rows[0]->amount, $rows[0]->currency, $rows[0]->ips, $rows[0]->region, $rows[0]->issuerCountry, $rows[0]->resolution, $rows[0]->wallet,
        ]);
        $this->assertSame(['535456', '1378'], [$rows[0]->cardBin, $rows[0]->cardLast4]);
        $this->assertSame(['179', 'visa', 'non_eu', 'apple_pay'], [$rows[1]->amount, $rows[1]->ips, $rows[1]->region, $rows[1]->wallet]);

        $this->assertSame(OperationType::Decline, MerchantOperation::classify(['trn_type' => 'DEPOSIT', 'amount' => '5.02', 'resolution' => 'DECLINED']));
        $this->assertSame(OperationType::Sale, MerchantOperation::classify(['trn_type' => 'DEPOSIT', 'amount' => '179', 'resolution' => 'COMPLETED']));
    }

    public function test_corefy_export_reads_account_payer_and_skips_pending_invoices()
    {
        $file = $this->csvFile([
            ['created', 'updated', 'id', 'reference_id', 'service_method', 'amount', 'currency', 'status', 'account', 'resolution', 'payer', 'issuer_country', 'payment_card_issuer_name'],
            ['2026-10-08 09:47:53', '2026-10-08 09:48:10', 'cpi_1', 'pay_1', 'payment_card', '88.00', 'EUR', 'processed', 'coma_d91', 'ok', '523641******0410', 'PT', 'BANK A'],
            ['2026-10-08 12:42:12', '2026-10-08 12:43:00', 'cpi_2', 'pay_2', 'payment_card', '48.00', 'EUR', 'process_failed', 'coma_d91', 'issuer_decline', '431947******3361', 'IE', 'BANK B'],
            ['2026-10-06 08:00:46', '2026-10-06 08:00:50', 'cpi_3', 'order_mc_1', 'payment_card', '5.01', 'EUR', 'process_pending', 'coma_d91', 'ok', '535456******1378', 'LV', 'BANK C'],
            ['2026-10-07 13:38:33', '2026-10-07 13:39:00', 'cpi_4', 'pay_4', 'payment_card', '26.50', 'EUR', 'refunded', 'coma_d91', 'ok', '549619******2297', 'ES', 'BANK D'],
        ], 'corefy.csv');

        $rows = $this->parse('corefy', $file);

        $this->assertCount(3, $rows); // the pending invoice is not an operation yet
        $this->assertSame(['coma_d91', 'cpi_1', '88.00', 'EUR', '523641', '0410', 'mastercard', 'eu'], [
            $rows[0]->mid, $rows[0]->paymentId, $rows[0]->amount, $rows[0]->currency, $rows[0]->cardBin, $rows[0]->cardLast4, $rows[0]->ips, $rows[0]->region,
        ]);
        $this->assertSame(OperationType::Decline, MerchantOperation::classify(['amount' => $rows[1]->amount, 'resolution' => $rows[1]->resolution, 'processing_code' => $rows[1]->processingCode]));
        // A refunded payment was approved at the gateway: it still counts as a sale there.
        $this->assertSame(OperationType::Sale, MerchantOperation::classify(['amount' => $rows[2]->amount, 'resolution' => $rows[2]->resolution, 'processing_code' => $rows[2]->processingCode]));
    }

    public function test_a_file_without_a_recognisable_header_yields_nothing()
    {
        $rows = $this->parse('cardaq', $this->csvFile([['hello', 'world'], ['1', '2']]));

        $this->assertSame([], $rows);
    }
}

<?php

namespace Tests\Feature\Reports;

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

    public function test_a_file_without_a_recognisable_header_yields_nothing()
    {
        $rows = $this->parse('cardaq', $this->csvFile([['hello', 'world'], ['1', '2']]));

        $this->assertSame([], $rows);
    }
}

<?php

namespace Tests\Feature\Reports;

use App\Models\Client;
use App\Models\Company;
use App\Models\DailyReportTask;
use App\Reports\Ingestion\ReportIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class ReserveClientCheckTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    public function test_a_clients_reserve_table_is_lined_up_with_our_reports_of_the_same_mid()
    {
        Storage::fake('local');
        Mail::fake();
        $client = Client::query()->create(['name' => 'Luneholm group', 'invoice_email' => 'finance@luneholm.test']);
        $company = Company::factory()->create(['client_id' => $client->id]);
        $mid = $this->mid($this->merchantWithTariff(['company_id' => $company->id]), $this->cardaq(), $this->corefy(), ['mid' => '4400000001']);
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($mid->bankProvider, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));
        $ingestion->ingest($mid->gateProvider, $this->corefyCsv(), CarbonImmutable::parse('2026-09-15'));
        $ours = (float) DailyReportTask::query()->where('report_date', '2026-09-15')->sole()->getRawOriginal('reserve_amount');

        // Their sheet: the day with our turnover (350) and our reserve, a day we have no report for, and a thousands point.
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('CLIENT-01');
        $sheet->fromArray([
            ['MID', 'Start Period', 'End Period', 'Processed Amount', 'RR Provider'],
            ['CLIENT-01', '2026-09-15', '2026-09-15', 350, $ours],
            ['CLIENT-01', '2026-09-16', '2026-09-16', 0.4, 40],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'rr').'.xlsx';
        (new Xlsx($book))->save($path);

        $exit = Artisan::call('reserve:client-check', ['file' => $path, '--client' => $client->id, '--map' => ['CLIENT-01=4400000001']]);
        $output = Artisan::output();
        @unlink($path);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('MID 4400000001', $output, $output);
        $this->assertStringContainsString('350.00', $output, $output);
        $this->assertStringContainsString('no report', $output, $output);
        $this->assertStringContainsString('400.00*', $output, $output);

        $this->assertSame(1, Artisan::call('reserve:client-check', ['file' => $path, '--client' => 999999]));
    }
}

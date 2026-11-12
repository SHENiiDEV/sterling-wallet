<?php

namespace Tests\Feature\Reports;

use App\Reports\Ingestion\ReportIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class CorefyUtcExportTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    public function test_the_dashboard_export_is_read_as_utc_and_the_bot_file_as_the_providers_local_time()
    {
        Storage::fake('local');
        Mail::fake();
        $corefy = $this->corefy();
        $mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), $corefy);
        $ingestion = app(ReportIngestionService::class);

        // The dashboard export has its processing columns and UTC times: 22:30 UTC is 00:30 in Riga, the next day.
        $export = $this->csvFile([
            ['id', 'account', 'status', 'resolution', 'amount', 'currency', 'payer', 'created', 'processed_amount'],
            ['cpi_EXPORT', 'coma_TEST1', 'processed', 'ok', '100.00', 'EUR', '411111******1111', '2026-09-15 22:30:00', '100.00'],
        ], 'export.csv', ',');
        $ingestion->ingest($corefy, $export, CarbonImmutable::parse('2026-09-16'));

        // The bot's file: the same clock reading is Riga time.
        $bot = $this->csvFile([
            ['ID', 'Commerce account', 'Status', 'Resolution', 'Amount', 'Currency', 'Card', 'Created'],
            ['cpi_BOT', 'coma_TEST1', 'processed', 'ok', '100.00', 'EUR', '411111******1111', '2026-09-15 22:30:00'],
        ], 'bot.csv', ';');
        $ingestion->ingest($corefy, $bot, CarbonImmutable::parse('2026-09-15'));

        $export = $mid->operations()->where('payment_id', 'cpi_EXPORT')->sole();
        $this->assertSame('2026-09-15 22:30:00', $export->transaction_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-16', $export->report_date->toDateString());

        $bot = $mid->operations()->where('payment_id', 'cpi_BOT')->sole();
        $this->assertSame('2026-09-15 20:30:00', $bot->transaction_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-15', $bot->report_date->toDateString());
    }
}

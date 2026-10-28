<?php

namespace Tests\Feature\Reports;

use App\Models\DailyReportTask;
use App\Reports\Ingestion\ReportIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class FileDiffTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    public function test_it_shows_how_far_a_report_is_from_the_acquirer_file_it_was_built_from()
    {
        Storage::fake('local');
        Mail::fake();

        $mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), null);
        app(ReportIngestionService::class)->ingest($mid->bankProvider, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));

        $this->artisan('reports:file-diff', ['--only-diff' => true])->expectsOutputToContain('Locked reports')->assertSuccessful();

        // A report that was paid on a different basis: 350.00 in the file, 300.00 in the report.
        DailyReportTask::query()->sole()->update(['turnover' => '300.00']);

        $this->artisan('reports:file-diff', ['--only-diff' => true])->expectsOutputToContain('50.00')->assertSuccessful();
    }

    public function test_stored_acquirer_files_can_be_read_again_and_stay_on_their_day()
    {
        Storage::fake('local');
        Mail::fake();

        $mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), null);
        app(ReportIngestionService::class)->ingest($mid->bankProvider, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));

        $this->artisan('reports:reingest-bank')->expectsOutputToContain('1 file(s)')->assertSuccessful();
        $this->artisan('reports:reingest-bank', ['--apply' => true])->expectsOutputToContain('row(s) read')->assertSuccessful();

        $task = DailyReportTask::query()->sole();
        $this->assertSame('2026-09-15', $task->report_date->toDateString());
        $this->assertSame(4, $task->fresh()->sources()->sole()->rows_count);
    }

    public function test_the_pairing_report_lists_unpaired_sales_per_day()
    {
        Storage::fake('local');
        Mail::fake();

        $mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), $this->corefy());
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($mid->bankProvider, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));

        // Only the bank side so far: its three sales have no pair.
        $this->artisan('reports:pairing', ['--days' => 365])
            ->expectsOutputToContain('MID '.$mid->mid)
            ->expectsOutputToContain('2026-09-15')
            ->assertSuccessful();
    }
}

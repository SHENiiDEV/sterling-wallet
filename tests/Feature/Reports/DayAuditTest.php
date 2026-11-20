<?php

namespace Tests\Feature\Reports;

use App\Reports\Ingestion\ReportIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class DayAuditTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    public function test_the_day_audit_shows_what_is_on_the_day()
    {
        Storage::fake('local');
        Mail::fake();
        $mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), $this->corefy());
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($mid->bankProvider, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));
        $ingestion->ingest($mid->gateProvider, $this->corefyCsv(), CarbonImmutable::parse('2026-09-15'));

        $exit = Artisan::call('reports:day-audit', ['--mid' => $mid->mid, '--date' => '2026-09-15']);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Stored: sales 3', $output, $output);
        $this->assertStringContainsString('Largest sales', $output, $output);
        $this->assertStringContainsString('200.00', $output, $output);
        $this->assertSame(1, Artisan::call('reports:day-audit', ['--mid' => $mid->mid, '--date' => '2020-01-01']));
    }
}

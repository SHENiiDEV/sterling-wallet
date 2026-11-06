<?php

namespace Tests\Feature\Reports;

use App\Models\DailyReportTask;
use App\Models\MerchantMid;
use App\Reports\Generation\DailyReportGenerator;
use App\Reports\Ingestion\ReportIngestionService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class ReportPreviewTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private MerchantMid $mid;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        $this->mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), $this->corefy());
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($this->mid->bankProvider, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));
        $ingestion->ingest($this->mid->gateProvider, $this->corefyCsv(), CarbonImmutable::parse('2026-09-15'));
    }

    public function test_the_preview_shows_what_a_report_would_be_now_and_changes_nothing()
    {
        $task = DailyReportTask::query()->where('report_date', '2026-09-15')->sole();
        $right = (string) $task->getRawOriginal('net_payout');
        // A report that was calculated before its data was complete: stored with nothing.
        $task->update(['net_payout' => '0', 'sales_count' => 0, 'turnover' => '0', 'total_merchant_fee' => '0']);

        $preview = app(DailyReportGenerator::class)->preview($task->fresh());

        $this->assertTrue(BigDecimal::of($preview['before']['payout'])->isZero());
        $this->assertSame([0, 3], [$preview['before']['sales'], $preview['after']['sales']]);
        $this->assertTrue(BigDecimal::of($preview['after']['payout'])->isEqualTo($right), "Expected {$right}, got {$preview['after']['payout']}");

        // Nothing was saved.
        $stored = $task->fresh();
        $this->assertTrue(BigDecimal::of($stored->getRawOriginal('net_payout'))->isZero());
        $this->assertSame(0, $stored->sales_count);
    }

    public function test_the_command_prints_the_difference_per_report_and_in_total()
    {
        $task = DailyReportTask::query()->where('report_date', '2026-09-15')->sole();
        $task->update(['net_payout' => '0', 'turnover' => '0', 'total_merchant_fee' => '0']);

        $this->artisan('reports:recalc-preview', ['--mid' => $this->mid->mid])
            ->expectsOutputToContain('2026-09-15')
            ->expectsOutputToContain('Payout difference in total: +')
            ->assertSuccessful();

        $this->artisan('reports:recalc-preview')->assertFailed();
    }
}

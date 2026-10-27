<?php

namespace Tests\Feature\Reports;

use App\Enums\MerchantStatus;
use App\Enums\MidStatus;
use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Jobs\GenerateDailyReportJob;
use App\Models\DailyReportTask;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Reports\Ingestion\ReportIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class IngestionTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake([GenerateDailyReportJob::class]);
    }

    private function ingest(...$args)
    {
        return app(ReportIngestionService::class)->ingest(...$args);
    }

    public function test_acquirer_file_is_split_into_operations_and_waits_for_the_gateway()
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(), $cardaq, $corefy);

        $result = $this->ingest($cardaq, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));

        $this->assertSame(4, $result->rows);
        $this->assertSame(4, $mid->operations()->count());
        $this->assertSame(3, $mid->operations()->where('operation_type', OperationType::Sale)->count());
        $this->assertSame(1, $mid->operations()->where('operation_type', OperationType::Refund)->count());
        $this->assertSame(ProviderType::Bank, $mid->operations()->first()->role);

        $task = DailyReportTask::query()->sole();
        $this->assertSame(ReportStatus::Partial, $task->status);
        $this->assertSame('2026-09-15', $task->report_date->toDateString());
        $this->assertSame([$corefy->id], $task->missingProviderIds());
        $this->assertSame(4, $task->sources()->sole()->rows_count);
        Storage::disk('local')->assertExists($task->sources()->sole()->file_path);
        $this->assertStringStartsWith('reports/raw/cardaq/2026-09-15/', $task->sources()->sole()->file_path);
        Queue::assertNothingPushed();

        $this->ingest($corefy, $this->corefyCsv(), CarbonImmutable::parse('2026-09-15'));

        $this->assertSame(ReportStatus::Pending, $task->fresh()->status);
        $this->assertSame([], $task->fresh()->missingProviderIds());
        $this->assertSame(5, $mid->operations()->where('role', ProviderType::Gate)->count());
        Queue::assertPushed(GenerateDailyReportJob::class, fn ($job) => $job->taskId === $task->id);
    }

    public function test_uploading_the_same_file_twice_does_not_duplicate_operations()
    {
        $cardaq = $this->cardaq();
        $this->mid($this->merchantWithTariff(), $cardaq, null);

        $this->ingest($cardaq, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));
        $this->ingest($cardaq, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));

        $this->assertSame(4, MerchantOperation::query()->count());
        $this->assertSame(1, DailyReportTask::query()->count());
    }

    public function test_a_mid_without_gateway_is_ready_with_the_acquirer_file_alone()
    {
        $cardaq = $this->cardaq();
        $this->mid($this->merchantWithTariff(), $cardaq, null);

        $this->ingest($cardaq, $this->cardaqCsv(), CarbonImmutable::parse('2026-09-15'));

        Queue::assertPushed(GenerateDailyReportJob::class);
    }

    public function test_unknown_mid_is_parked_in_review()
    {
        $cardaq = $this->cardaq();

        $result = $this->ingest($cardaq, $this->cardaqCsv('4499999999'), CarbonImmutable::parse('2026-09-15'));

        $this->assertSame(['4499999999'], $result->unknownMids);
        $mid = MerchantMid::query()->where('mid', '4499999999')->sole();
        $this->assertSame(MidStatus::Review, $mid->status);
        $this->assertSame(MerchantStatus::Review, $mid->merchant->status);
        $this->assertSame('SHOP LTD', $mid->merchant->name);
        $this->assertSame($cardaq->id, $mid->bank_provider_id);
        $this->assertSame(4, $mid->operations()->count());
    }

    public function test_file_date_wins_over_the_hint_and_weekend_days_share_one_report()
    {
        $cardaq = $this->cardaq();
        $this->mid($this->merchantWithTariff(), $cardaq, null);

        // Friday operations, hinted as Friday → the weekend (Sunday) report.
        $this->ingest($cardaq, $this->cardaqCsv(day: '2026-09-11'), CarbonImmutable::parse('2026-09-11'));

        $task = DailyReportTask::query()->sole();
        $this->assertSame(['2026-09-13', '2026-09-11', '2026-09-13'], [
            $task->report_date->toDateString(), $task->period_from->toDateString(), $task->period_to->toDateString(),
        ]);
    }

    public function test_without_any_date_rows_go_to_their_own_day()
    {
        $cardaq = $this->cardaq();
        $this->mid($this->merchantWithTariff(), $cardaq, null);

        $this->ingest($cardaq, $this->cardaqCsv(day: '2026-09-16'));

        $this->assertSame('2026-09-16', DailyReportTask::query()->sole()->report_date->toDateString());
    }

    public function test_a_cardaq_file_belongs_wholly_to_the_day_most_of_its_payments_were_made_on()
    {
        $cardaq = $this->cardaq();
        $this->mid($this->merchantWithTariff(), $cardaq, null);

        // The clearing batch ends just after midnight: three payments of the 15th and one of the 16th at 00:05.
        $this->ingest($cardaq, $this->csvFile([
            ['Merchant name', 'MID', 'Transaction ID', 'ARN', 'Card number', 'Card brand', 'Region', 'Trn type', 'Amount', 'Currency', 'Trn date'],
            ['SHOP LTD', '4400000001', 'CQ-1', '74000000000000000000001', '411111******1111', 'VISA', 'EU', '5', '100.00', 'EUR', '2026-09-15 10:00:00'],
            ['SHOP LTD', '4400000001', 'CQ-2', '74000000000000000000002', '555555******4444', 'MASTERCARD', 'NON-EU', '5', '200.00', 'EUR', '2026-09-15 11:00:00'],
            ['SHOP LTD', '4400000001', 'CQ-3', '74000000000000000000003', '422222******2222', 'VISA', 'NON-EU', '5', '50.00', 'EUR', '2026-09-15 23:59:00'],
            ['SHOP LTD', '4400000001', 'CQ-4', '74000000000000000000004', '411111******1111', 'VISA', 'EU', '5', '25.00', 'EUR', '2026-09-16 00:05:00'],
        ], 'cardaq.csv'));

        $task = DailyReportTask::query()->sole();
        $this->assertSame('2026-09-15', $task->report_date->toDateString());
        $this->assertSame(4, $task->sources()->sole()->rows_count);
    }

    public function test_an_empty_export_marks_covered_mids_as_received()
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(), $cardaq, $corefy);

        app(ReportIngestionService::class)->markEmpty($corefy, CarbonImmutable::parse('2026-09-15'), [$mid]);

        $task = DailyReportTask::query()->sole();
        $this->assertSame([$cardaq->id], $task->missingProviderIds());
        $this->assertSame(0, $task->sources()->sole()->rows_count);
    }

    public function test_gateway_rows_find_their_mid_by_gate_mid()
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(), $cardaq, $corefy);

        $result = $this->ingest($corefy, $this->corefyCsv('coma_UNKNOWN'), CarbonImmutable::parse('2026-09-15'));
        $this->assertSame(0, $result->rows);
        $this->assertNotEmpty($result->warnings);

        $this->ingest($corefy, $this->corefyCsv(), CarbonImmutable::parse('2026-09-15'));
        $this->assertSame(5, $mid->operations()->count());
    }

    public function test_extra_gate_accounts_resolve_to_the_same_mid()
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(), $cardaq, $corefy);
        $mid->update(['extra_gate_mids' => ['coma_SECOND']]);

        $this->ingest($corefy, $this->corefyCsv('coma_SECOND'), CarbonImmutable::parse('2026-09-15'));

        $this->assertSame(5, $mid->operations()->count());
        $this->assertSame(['coma_TEST1', 'coma_SECOND'], $mid->fresh()->gateAccounts());
    }
}

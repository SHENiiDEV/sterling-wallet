<?php

namespace Tests\Feature\Api;

use App\Enums\ReportStatus;
use App\Models\DailyReportTask;
use App\Models\MerchantOperation;
use App\Reports\Ingestion\ReportIngestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class BotReportApiTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sterling.bot_api_key' => 'secret-token']);
        Storage::fake('local');
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-17 09:00:00', 'Europe/Riga'));
    }

    private function bot(): static
    {
        return $this->withToken('secret-token');
    }

    public function test_a_valid_token_is_required()
    {
        $this->getJson('/api/v1/reports/pending-tasks?provider=cardaq')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/v1/reports/pending-tasks?provider=cardaq')->assertUnauthorized();

        config(['sterling.bot_api_key' => null]);
        $this->withToken('')->getJson('/api/v1/reports/pending-tasks?provider=cardaq')->assertUnauthorized();
    }

    public function test_pending_tasks_list_missing_dates_per_mid_in_the_old_bot_format()
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(['name' => 'Price Padel']), $cardaq, $corefy, ['reports_start_date' => '2026-09-14']);

        // Corefy already delivered Monday.
        app(ReportIngestionService::class)->markEmpty($corefy, CarbonImmutable::parse('2026-09-14'), [$mid]);

        $this->bot()->getJson('/api/v1/reports/pending-tasks?provider=corefy')
            ->assertOk()
            ->assertJsonPath('provider', 'corefy')
            ->assertJsonPath('companies.0.id', 'coma_TEST1')
            ->assertJsonPath('companies.0.merchant_wallet_id', $mid->id)
            ->assertJsonPath('companies.0.role', 'gate')
            ->assertJsonPath('companies.0.pending_reports.*.report_date', ['2026-09-15', '2026-09-16']);

        // Cardaq is 2 days behind: Wednesday isn't ready yet.
        $this->bot()->getJson('/api/v1/reports/pending-tasks?provider=cardaq')
            ->assertOk()
            ->assertJsonPath('companies.0.id', '4400000001')
            ->assertJsonPath('companies.0.pending_reports.*.report_date', ['2026-09-14', '2026-09-15'])
            ->assertJsonPath('companies.0.pending_reports.0.date_range', ['from' => '2026-09-14', 'to' => '2026-09-14']);
    }

    public function test_weekend_tasks_cover_friday_to_sunday()
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 09:00:00', 'Europe/Riga'));
        $corefy = $this->corefy();
        $this->mid($this->merchantWithTariff(), $this->cardaq(), $corefy, ['reports_start_date' => '2026-09-11']);

        $this->bot()->getJson('/api/v1/reports/pending-tasks?provider=corefy')
            ->assertJsonPath('companies.0.pending_reports.0', [
                'report_date' => '2026-09-13',
                'from' => '2026-09-11',
                'to' => '2026-09-13',
                'date_range' => ['from' => '2026-09-11', 'to' => '2026-09-13'],
            ]);
    }

    public function test_one_upload_endpoint_serves_every_provider()
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(), $cardaq, $corefy);

        $this->bot()->post('/api/v1/reports/upload/cardaq', [
            'merchant_id' => $mid->id,
            'report_date' => '2026-09-15',
            'file' => $this->cardaqCsv(),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('rows', 4)
            ->assertJsonPath('reports.0.status', 'partial');

        $this->bot()->post('/api/v1/reports/upload/corefy', [
            'merchant_id' => $mid->id,
            'report_date' => '2026-09-15',
            'file' => $this->corefyCsv(),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('rows', 5);

        $this->assertSame(ReportStatus::Completed, DailyReportTask::query()->sole()->status);
    }

    public function test_an_empty_corefy_export_with_headers_only_marks_the_day_received()
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(), $cardaq, $corefy);

        // What the old bot uploads for "Rows: 0".
        $this->bot()->post('/api/v1/reports/upload/corefy', [
            'merchant_id' => $mid->id,
            'report_date' => '2026-09-15',
            'file' => $this->csvFile([['id', 'status', 'created_at', 'amount', 'currency']]),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('rows', 0);

        $this->assertSame([$cardaq->id], DailyReportTask::query()->sole()->missingProviderIds());
    }

    public function test_legacy_transactions_import_goes_to_cardaq()
    {
        $cardaq = $this->cardaq();
        $this->mid($this->merchantWithTariff(), $cardaq, null);

        $this->bot()->post('/api/v1/transactions/import', [
            'report_date' => '2026-09-15',
            'file' => $this->cardaqCsv(),
        ], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(4, MerchantOperation::query()->where('provider_id', $cardaq->id)->count());
    }

    public function test_unknown_provider_or_format_is_rejected()
    {
        $this->bot()->post('/api/v1/reports/upload/nope', ['file' => $this->cardaqCsv()], ['Accept' => 'application/json'])->assertNotFound();

        $this->cardaq(['code' => 'noformat', 'name' => 'No format', 'report_format' => null]);
        $this->bot()->post('/api/v1/reports/upload/noformat', ['file' => $this->cardaqCsv()], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Provider noformat has no report format set.');
    }
}

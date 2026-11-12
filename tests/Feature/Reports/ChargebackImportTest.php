<?php

namespace Tests\Feature\Reports;

use App\Enums\OperationType;
use App\Models\DailyReportTask;
use App\Models\FxRate;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\User;
use App\Reports\Ingestion\ChargebackImporter;
use App\Reports\Ingestion\ReportIngestionService;
use App\Settlements\SettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class ChargebackImportTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private User $admin;

    private Merchant $merchant;

    private MerchantMid $mid;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        $this->travelTo('2026-09-20');
        $this->admin = User::factory()->superAdmin()->create();
        $this->merchant = $this->merchantWithTariff(['crypto_provider_id' => $this->oxen()->id]);
        $this->mid = $this->mid($this->merchant, $this->madfin(), null, ['mid' => '5500000001']);
        $this->merchant->wallets()->create(['type' => 'provider_inflow', 'currency' => 'USDC', 'network' => 'TRC20', 'address' => 'TXabc']);
        FxRate::query()->create(['rate_date' => '2026-09-01', 'base' => 'EUR', 'quote' => 'USDC', 'rate' => '1.10']);
        app(ReportIngestionService::class)->ingest($this->mid->bankProvider, $this->madfinCsv('5500000001'), CarbonImmutable::parse('2026-09-15'));
    }

    private function list(array $rows): UploadedFile
    {
        return $this->csvFile([['VROL number', 'ARN', 'Transaction Date', 'Card', 'Trx Amount', 'Currency', 'Reason Code', 'Transaction UID'], ...$rows], 'chargebacks.csv', ',');
    }

    public function test_a_chargeback_is_found_on_its_payment_and_charged_to_the_report_of_that_day()
    {
        $importer = app(ChargebackImporter::class);
        $file = $this->list([
            ['1001', 'A2', '15/09/2026', '555555***4444', '200,00', 'EUR', '13.1 - Merchandise/Services Not Received', 'MF-2'],
            ['1002', 'NOPE', '15/09/2026', '411111***1111', '1 092,54', 'EUR', '13.7 - Cancelled Merchandise/Services', 'unknown-uid'],
        ]);
        $this->assertTrue(ChargebackImporter::looksLikeChargebacks($file));

        $before = (float) DailyReportTask::query()->where('report_date', '2026-09-15')->sole()->getRawOriginal('net_payout');
        $result = $importer->import($this->mid->bankProvider, $file);

        $this->assertSame([1, ['1002']], [$result['rows'], $result['not_found']]);
        $operation = MerchantOperation::query()->where('payment_id', 'chargeback:1001')->sole();
        $this->assertSame(OperationType::Chargeback, $operation->operation_type);
        $this->assertSame('2026-09-15', $operation->report_date->toDateString());
        $this->assertSame('MF-2', $operation->reference);

        $report = DailyReportTask::query()->where('report_date', '2026-09-15')->sole();
        $this->assertEqualsWithDelta(200.0, (float) $report->getRawOriginal('chargebacks_amount'), 0.001);
        $this->assertLessThan($before - 199, (float) $report->getRawOriginal('net_payout'));

        // The same list again changes nothing.
        $importer->import($this->mid->bankProvider, $file);
        $this->assertSame(1, MerchantOperation::query()->where('operation_type', OperationType::Chargeback)->count());
    }

    public function test_a_chargeback_on_a_paid_report_moves_to_the_next_report_that_is_not_paid()
    {
        $draft = app(SettlementService::class)->createDraft($this->merchant, $this->admin);
        app(SettlementService::class)->recordPaid($draft, $this->admin, CarbonImmutable::parse('2026-09-18'));

        $this->actingAs($this->admin)->post(route('admin.reports.upload'), [
            'provider_id' => $this->mid->bank_provider_id,
            'file' => $this->list([['2001', 'A3', '15/09/2026', '422222***2222', '50,00', 'EUR', '13.1 - Merchandise/Services Not Received', 'MF-3']]),
        ])->assertSessionHasNoErrors();

        $operation = MerchantOperation::query()->where('payment_id', 'chargeback:2001')->sole();
        $this->assertSame('2026-09-16', $operation->report_date->toDateString());
        // The paid report is untouched.
        $this->assertEqualsWithDelta(0.0, (float) DailyReportTask::query()->where('report_date', '2026-09-15')->sole()->getRawOriginal('chargebacks_amount'), 0.001);
    }
}

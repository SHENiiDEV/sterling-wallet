<?php

namespace Tests\Feature\Reports;

use App\Enums\Currency;
use App\Enums\MidStatus;
use App\Enums\ReportStatus;
use App\Enums\ReserveEntryType;
use App\Mail\DailyReportMail;
use App\Models\DailyReportTask;
use App\Models\MerchantMid;
use App\Models\ReserveLedgerEntry;
use App\Reports\Generation\DailyReportGenerator;
use App\Reports\Ingestion\ReportIngestionService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

/**
 * Worked example (EUR MID, Cardaq ↔ Corefy, Oxen for conversion):
 *
 *   sales: Visa EU 100, MC non-EU 200, Visa non-EU 50 → turnover 350.00; refund 30.00; 2 declines at the gateway
 *   merchant fee:  3% × 100 + 4% × 200 + 4% × 50 = 13.00; fixed 3 × 0.20 + 1 × 1.00 + 2 × 0.10 = 1.80 → 14.80
 *   acquirer cost: 1.5% × 100 + 2.5% × 200 + 2.5% × 50 = 7.75; + 3 × 0.05                          →  7.90
 *   gateway cost:  3 × 0.10 success + 2 × 0.05 decline                                              →  0.40
 *   reserve:       10% × (350 − 30 − 14.80 = 305.20)                                                → 30.52
 *   net volume:    305.20 − 30.52 = 274.68; conversion 0.4% → 1.10; Oxen cost 0.25% → 0.69
 *   payout:        274.68 − 1.10 = 273.58
 *   net profit:    14.80 + 1.10 − (7.90 + 0.40 + 0.69) = 6.91
 */
class DailyReportGeneratorTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
    }

    private function ingestPair(MerchantMid $mid, $bankFile, string $day = '2026-09-15'): DailyReportTask
    {
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($mid->bankProvider, $bankFile, CarbonImmutable::parse($day));
        if ($mid->gateProvider) {
            $ingestion->ingest($mid->gateProvider, $this->corefyCsv($mid->gate_mid, $mid->currency->value, $day), CarbonImmutable::parse($day));
        }

        return DailyReportTask::query()->where('merchant_mid_id', $mid->id)->sole();
    }

    private function assertMoney(string $expected, mixed $actual): void
    {
        $this->assertTrue(BigDecimal::of((string) $actual)->isEqualTo($expected), "Expected {$expected}, got {$actual}.");
    }

    private function assertWorkedExample(DailyReportTask $task): void
    {
        $this->assertSame(ReportStatus::Completed, $task->status, (string) $task->error_log);
        $this->assertSame(3, $task->sales_count);
        $this->assertMoney('350.00', $task->turnover);
        $this->assertMoney('30.00', $task->refunds_amount);
        $this->assertMoney('14.80', $task->total_merchant_fee);
        $this->assertMoney('8.99', $task->total_provider_cost);
        $this->assertMoney('30.52', $task->reserve_amount);
        $this->assertMoney('274.68', $task->net_volume);
        $this->assertMoney('1.10', $task->conversion_fee);
        $this->assertMoney('273.58', $task->net_payout);
        $this->assertMoney('6.91', $task->net_profit);
        $this->assertSame(['bank' => '7.90', 'gate' => '0.40', 'crypto' => '0.69'], $task->summary_data['provider_cost']);
        $this->assertSame(2, $task->summary_data['counts']['declines']);
        $this->assertSame(0, $task->summary_data['counts']['unmatched_bank'] - 1); // the refund has no gateway pair in this file
    }

    public function test_cardaq_corefy_worked_example()
    {
        $merchant = $this->merchantWithTariff(['crypto_provider_id' => $this->oxen()->id]);
        $mid = $this->mid($merchant, $this->cardaq(), $this->corefy());

        $task = $this->ingestPair($mid, $this->cardaqCsv())->fresh();

        $this->assertWorkedExample($task);
        $this->assertSame('EUR', $task->base_currency);
        $this->assertMoney('1.00000000', $task->fx_rate);
        $this->assertMoney('6.9100', $task->net_profit_base);

        // Reconciliation ran inside generation: the three sales are paired.
        $this->assertSame(3, $mid->operations()->where('role', 'bank')->whereNotNull('matched_operation_id')->count());

        // Files and e-mail.
        foreach (['generated_xlsx_path', 'generated_pdf_path', 'generated_operations_path'] as $file) {
            Storage::disk('local')->assertExists($task->{$file});
        }
        Mail::assertQueued(DailyReportMail::class, fn ($mail) => $mail->hasTo('billing@merchant.test'));
        $this->assertTrue($task->is_email_sent);

        // Reserve held until report date + 180 days.
        $hold = ReserveLedgerEntry::query()->sole();
        $this->assertSame(ReserveEntryType::Hold, $hold->type);
        $this->assertMoney('30.52', $hold->amount);
        $this->assertSame('2027-03-14', $hold->release_on->toDateString());
    }

    public function test_madfin_corefy_pair_gives_the_same_result_on_the_same_stand()
    {
        $oxen = $this->oxen();
        $corefy = $this->corefy();

        $cardaqMid = $this->mid($this->merchantWithTariff(['crypto_provider_id' => $oxen->id]), $this->cardaq(), $corefy);
        $madfinMid = $this->mid($this->merchantWithTariff(['crypto_provider_id' => $oxen->id]), $this->madfin(), $corefy, [
            'mid' => '5500000001', 'gate_mid' => 'coma_MADFIN',
        ]);

        $this->assertWorkedExample($this->ingestPair($cardaqMid, $this->cardaqCsv())->fresh());
        $this->assertWorkedExample($this->ingestPair($madfinMid, $this->madfinCsv())->fresh());
    }

    public function test_usd_mid_is_calculated_in_usd_and_converted_at_the_frozen_rate()
    {
        $this->eurUsdRate('2026-09-01', '1.10');
        $merchant = $this->merchantWithTariff(['crypto_provider_id' => $this->oxen()->id]);
        $mid = $this->mid($merchant, $this->cardaq(), $this->corefy(), ['currency' => Currency::Usd]);

        $task = $this->ingestPair($mid, $this->cardaqCsv(currency: 'USD'))->fresh();

        $this->assertWorkedExample($task);
        $this->assertSame('USD', $task->currency);
        $this->assertMoney('0.90909091', $task->fx_rate);
        $this->assertMoney('318.1818', $task->turnover_base);
        $this->assertMoney('6.2818', $task->net_profit_base);

        // A later rate does not change a generated report until it is regenerated.
        $this->eurUsdRate('2026-09-10', '1.25');
        $this->assertMoney('0.90909091', $task->fresh()->fx_rate);
    }

    public function test_gbp_mid_without_rate_is_blocked()
    {
        $mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), null, ['currency' => Currency::Gbp]);

        $task = $this->ingestPair($mid, $this->cardaqCsv(currency: 'GBP'))->fresh();

        $this->assertSame(ReportStatus::Blocked, $task->status);
        $this->assertStringContainsString('GBP→EUR', $task->error_log);
        $this->assertSame(0, ReserveLedgerEntry::query()->count());
    }

    public function test_regeneration_reverses_the_previous_hold()
    {
        $mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), $this->corefy());
        $task = $this->ingestPair($mid, $this->cardaqCsv());

        app(DailyReportGenerator::class)->generate($task->fresh());
        app(DailyReportGenerator::class)->generate($task->fresh());

        $this->assertMoney('30.52', $mid->reserveBalance());
        $this->assertSame(2, ReserveLedgerEntry::query()->where('type', ReserveEntryType::Adjustment)->count());
        Mail::assertQueued(DailyReportMail::class, 1);
    }

    public function test_reserve_is_capped_by_the_mid_limit()
    {
        $mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), $this->corefy(), ['rolling_reserve_limit' => 1000]);
        ReserveLedgerEntry::query()->create([
            'merchant_id' => $mid->merchant_id, 'merchant_mid_id' => $mid->id, 'currency' => 'EUR',
            'type' => ReserveEntryType::Hold, 'amount' => '990.00',
        ]);

        $task = $this->ingestPair($mid, $this->cardaqCsv())->fresh();

        $this->assertMoney('10.00', $task->reserve_amount);
        $this->assertMoney('295.20', $task->net_volume);
        $this->assertMoney('1000.00', $mid->reserveBalance());
    }

    public function test_weekend_report_sums_the_whole_period()
    {
        $mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), null);
        $ingestion = app(ReportIngestionService::class);

        // Two files that both belong to the Sunday report: Friday and Saturday operations.
        $ingestion->ingest($mid->bankProvider, $this->cardaqCsv(day: '2026-09-11'), CarbonImmutable::parse('2026-09-13'));
        $ingestion->ingest($mid->bankProvider, $this->csvFile([
            ['MID', 'Transaction ID', 'Card brand', 'Region', 'Amount', 'Currency', 'Trn date'],
            ['4400000001', 'CQ-SAT', 'VISA', 'EU', '10.00', 'EUR', '2026-09-12 09:00:00'],
        ]), CarbonImmutable::parse('2026-09-13'));

        $task = DailyReportTask::query()->sole();
        $this->assertSame(ReportStatus::Completed, $task->status);
        $this->assertSame(4, $task->sales_count);
        $this->assertMoney('360.00', $task->turnover);
    }

    public function test_reports_are_blocked_until_the_data_is_fixed()
    {
        $cardaq = $this->cardaq();

        $noTariff = $this->mid($this->merchantWithTariff(['fee_visa_eu_percent' => null, 'fee_acq_eu_percent' => 0]), $cardaq, null);
        $task = $this->ingestPair($noTariff, $this->cardaqCsv())->fresh();
        $this->assertSame(ReportStatus::Blocked, $task->status);
        $this->assertStringContainsString('fee_visa_eu_percent', $task->error_log);

        $review = $this->mid($this->merchantWithTariff(), $cardaq, null, ['mid' => '4400000002', 'status' => MidStatus::Review]);
        $task = $this->ingestPair($review, $this->cardaqCsv('4400000002'))->fresh();
        $this->assertSame(ReportStatus::Blocked, $task->status);
        $this->assertStringContainsString('review', $task->error_log);

        // Unknown MIDs are created in review, so their report is blocked as well.
        app(ReportIngestionService::class)->ingest($cardaq, $this->cardaqCsv('4499999999'), CarbonImmutable::parse('2026-09-15'));
        $unknown = MerchantMid::query()->where('mid', '4499999999')->sole();
        $this->assertSame(ReportStatus::Blocked, $unknown->dailyReports()->sole()->status);
    }
}

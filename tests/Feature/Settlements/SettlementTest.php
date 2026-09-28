<?php

namespace Tests\Feature\Settlements;

use App\Enums\ReportStatus;
use App\Enums\ReserveEntryType;
use App\Enums\SettlementStatus;
use App\Models\DailyReportTask;
use App\Models\FxRate;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\ReserveLedgerEntry;
use App\Models\Settlement;
use App\Models\User;
use App\Reports\Generation\DailyReportGenerator;
use App\Reports\Ingestion\ReportIngestionService;
use App\Settlements\ReserveReleaseService;
use App\Settlements\SettlementService;
use App\Settlements\SettlementStatementPdf;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class SettlementTest extends TestCase
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
        $this->admin = User::factory()->superAdmin()->create();
        $this->merchant = $this->merchantWithTariff(['crypto_provider_id' => $this->oxen()->id]);
        $this->mid = $this->mid($this->merchant, $this->cardaq(), $this->corefy());
        $this->merchant->wallets()->create(['type' => 'provider_inflow', 'currency' => 'USDC', 'network' => 'TRC20', 'address' => 'TXabc']);
        FxRate::query()->create(['rate_date' => '2026-09-01', 'base' => 'EUR', 'quote' => 'USDC', 'rate' => '1.10']);
    }

    private function report(string $day = '2026-09-15'): DailyReportTask
    {
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($this->mid->bankProvider, $this->cardaqCsv(day: $day), CarbonImmutable::parse($day));
        $ingestion->ingest($this->mid->gateProvider, $this->corefyCsv(day: $day), CarbonImmutable::parse($day));

        return DailyReportTask::query()->where('report_date', $day)->sole();
    }

    private function assertMoney(string $expected, mixed $actual): void
    {
        $this->assertTrue(BigDecimal::of((string) $actual)->isEqualTo($expected), "Expected {$expected}, got {$actual}.");
    }

    public function test_statement_shows_each_report_and_the_final_conversion()
    {
        $this->report();
        $settlement = app(SettlementService::class)->createDraft($this->merchant, $this->admin);
        app(SettlementService::class)->addAdjustment($settlement, 'Previous overpayment', 'EUR', '-10');

        $data = app(SettlementStatementPdf::class)->data($settlement->fresh());

        [$eur] = $data['reportGroups'];
        $this->assertSame('EUR', $eur['currency']);
        $this->assertSame(['2026-09-15', 3, '350.00', '30.00', '15.90', '30.52', '273.58'], [
            $eur['rows'][0]['date'], $eur['rows'][0]['sales'], (string) $eur['rows'][0]['turnover']->toScale(2),
            (string) $eur['rows'][0]['refunds']->toScale(2), (string) $eur['rows'][0]['fees']->toScale(2),
            (string) $eur['rows'][0]['reserve']->toScale(2), (string) $eur['rows'][0]['payout']->toScale(2),
        ]);

        [$conversion] = $data['conversion'];
        // 273.58 − 10.00 = 263.58 EUR × 1.10 = 289.94 USDC.
        $this->assertMoney('273.58', $conversion['reports']);
        $this->assertMoney('-10', $conversion['adjustments']);
        $this->assertMoney('263.58', $conversion['amount']);
        $this->assertMoney('289.94', $conversion['payout']);

        $html = view('settlements.statement', $data)->render();
        $this->assertStringContainsString('Final calculation', $html);
        $this->assertStringContainsString('289.94 USDC', $html);
        $this->assertStringContainsString('DRAFT', $html);
        $this->assertStringStartsWith('%PDF', app(SettlementStatementPdf::class)->render($settlement));
    }

    public function test_full_flow_draft_approve_settle_with_statement()
    {
        $report = $this->report();
        $this->assertSame(ReportStatus::Completed, $report->status);

        $this->actingAs($this->admin)->post(route('admin.settlements.store'), ['merchant' => $this->merchant->public_id])->assertRedirect();
        $settlement = Settlement::query()->sole();

        $this->assertSame(SettlementStatus::Draft, $settlement->status);
        $this->assertMatchesRegularExpression('/^SET-\d{4}-\d{6}$/', $settlement->number);
        $this->assertSame(['EUR' => '1.1'], $settlement->rates);
        // Payout 273.58 EUR × 1.10 = 300.94 USDC.
        $this->assertMoney('300.94', $settlement->total_payout);
        $this->assertNotNull($settlement->wallet_id);

        // Adjustment: previous overpayment of 10 EUR → −11.00 USDC.
        $this->actingAs($this->admin)->post(route('admin.settlements.adjustments.store', $settlement), [
            'description' => 'Previous overpayment', 'currency' => 'EUR', 'amount' => '-10',
        ])->assertRedirect();
        $this->assertMoney('289.94', $settlement->fresh()->total_payout);

        // The rate can be corrected by hand.
        $this->actingAs($this->admin)->put(route('admin.settlements.update', $settlement), [
            'rates' => ['EUR' => '1.2'], 'wallet_id' => $settlement->wallet_id,
        ])->assertRedirect();
        $this->assertMoney('316.30', $settlement->fresh()->total_payout); // (273.58 − 10) × 1.2

        $this->actingAs($this->admin)->post(route('admin.settlements.approve', $settlement))->assertSessionHasNoErrors();
        $this->assertSame(SettlementStatus::Approved, $settlement->fresh()->status);

        // Approved: no more edits, and the report is locked.
        $this->actingAs($this->admin)->post(route('admin.settlements.adjustments.store', $settlement), [
            'description' => 'late', 'currency' => 'EUR', 'amount' => '1',
        ])->assertSessionHasErrors('settlement');
        $this->assertStringContainsString($settlement->number, (string) $report->lockReason());

        $this->actingAs($this->admin)->post(route('admin.settlements.settle', $settlement), [
            'tx_hash' => '0xabc123',
            'proof' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $settlement->refresh();
        $this->assertSame(SettlementStatus::Settled, $settlement->status);
        $this->assertSame('0xabc123', $settlement->tx_hash);
        Storage::disk('local')->assertExists($settlement->proof_path);

        $this->actingAs($this->admin)->get(route('admin.settlements.pdf', $settlement))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Report Center shows it as paid out.
        $this->actingAs($this->admin)->get(route('admin.reports.index', ['month' => '2026-09']))
            ->assertInertia(fn (Assert $page) => $page->where('totals.0.paid_out', 273.58)->where('totals.0.payout', 273.58));
    }

    public function test_a_report_is_paid_by_only_one_active_settlement_and_cancel_frees_it()
    {
        $this->report();
        $service = app(SettlementService::class);

        $first = $service->createDraft($this->merchant, $this->admin);
        $second = $service->createDraft($this->merchant, $this->admin);
        $this->assertSame(1, $first->lines()->count());
        $this->assertSame(0, $second->lines()->count());

        $this->actingAs($this->admin)->post(route('admin.settlements.cancel', $first), ['reason' => 'wrong rate'])->assertRedirect();
        $this->assertSame(SettlementStatus::Cancelled, $first->fresh()->status);

        $third = $service->createDraft($this->merchant, $this->admin);
        $this->assertSame(1, $third->lines()->count());
    }

    public function test_approval_needs_rates_and_a_wallet()
    {
        $this->report();
        FxRate::query()->delete();
        $this->merchant->wallets()->delete();

        $this->actingAs($this->admin)->post(route('admin.settlements.store'), ['merchant' => $this->merchant->public_id]);
        $settlement = Settlement::query()->sole();

        $this->actingAs($this->admin)->post(route('admin.settlements.approve', $settlement))->assertSessionHasErrors('rates');

        app(SettlementService::class)->setRates($settlement, ['EUR' => '1.08']);
        $this->actingAs($this->admin)->post(route('admin.settlements.approve', $settlement))->assertSessionHasErrors('wallet_id');
    }

    public function test_regenerating_a_report_updates_its_draft_line_but_a_paid_report_is_locked()
    {
        $report = $this->report();
        $settlement = app(SettlementService::class)->createDraft($this->merchant, $this->admin);

        // New merchant tariff → regeneration changes the payout; the draft follows.
        $this->merchant->update(['fee_visa_eu_percent' => 5]);
        app(DailyReportGenerator::class)->generate($report->fresh(), false);
        $payout = $report->fresh()->getRawOriginal('net_payout');
        $this->assertTrue(BigDecimal::of($payout)->isLessThan('273.58'));
        $this->assertMoney($payout, $settlement->lines()->sole()->amount);

        app(SettlementService::class)->approve($settlement->fresh(), $this->admin);

        $this->actingAs($this->admin)->post(route('admin.reports.regenerate', $report))->assertSessionHasErrors('report');
        $this->actingAs($this->admin)->delete(route('admin.reports.destroy', $report))->assertSessionHasErrors('report');

        // Late data does not silently change an approved report.
        $this->merchant->update(['fee_visa_eu_percent' => 3]);
        app(DailyReportGenerator::class)->generate($report->fresh(), false);
        $this->assertSame($payout, $report->fresh()->getRawOriginal('net_payout'));
    }

    public function test_due_reserve_is_released_into_the_draft_settlement()
    {
        $report = $this->report();
        $this->assertMoney('30.52', $this->mid->reserveBalance());

        // Not due yet.
        $this->assertSame([], app(ReserveReleaseService::class)->releaseDue(CarbonImmutable::parse('2027-03-13')));

        $released = app(ReserveReleaseService::class)->releaseDue(CarbonImmutable::parse('2027-03-14'));
        $this->assertCount(1, $released);
        $this->assertSame(ReserveEntryType::Release, $released[0]->type);
        $this->assertMoney('-30.52', $released[0]->amount);
        $this->assertMoney('0', $this->mid->reserveBalance());

        $draft = Settlement::query()->sole();
        $this->assertSame(SettlementStatus::Draft, $draft->status);
        $this->assertSame('reserve_release', $draft->lines()->sole()->type->value);
        $this->assertMoney('33.57', $draft->total_payout); // 30.52 × 1.10

        // Idempotent, and the report is now locked.
        $this->assertSame([], app(ReserveReleaseService::class)->releaseDue(CarbonImmutable::parse('2027-04-01')));
        $this->assertNotNull($report->lockReason());

        $this->artisan('reserve:release')->assertSuccessful();
    }

    public function test_settlement_screens_render()
    {
        $this->report();
        $this->actingAs($this->admin)->get(route('admin.settlements.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/settlements/index')->where('pending.0.reports', 1));

        $settlement = app(SettlementService::class)->createDraft($this->merchant, $this->admin);
        $this->actingAs($this->admin)->get(route('admin.settlements.show', $settlement))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/settlements/show')->has('lines', 1)->has('wallets', 1));
    }

    public function test_report_center_grid_and_actions()
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-18 10:00', 'Europe/Riga'));
        $this->mid->update(['reports_start_date' => '2026-09-14']);
        $report = $this->report();

        $this->actingAs($this->admin)->get(route('admin.reports.index', ['month' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/reports/index')
                ->where('rows.0.cells.2026-09-15.status', 'completed')
                ->where('rows.0.cells.2026-09-14.status', 'missing')
                ->where('rows.0.cells.2026-09-16.status', 'missing')
                ->missing('rows.0.cells.2026-09-17')); // Cardaq is 2 days behind

        $this->actingAs($this->admin)->get(route('admin.reports.show', $report))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/reports/show')->has('sources', 2)->where('lockReason', null));

        $this->actingAs($this->admin)->get(route('admin.reports.download', [$report, 'pdf']))->assertOk();

        $this->actingAs($this->admin)->post(route('admin.reports.resend', $report))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->delete(route('admin.reports.destroy', $report))->assertRedirect();
        $this->assertNull($report->fresh());
        $this->assertMoney('0', $this->mid->reserveBalance());
        $this->assertSame(1, ReserveLedgerEntry::query()->where('type', ReserveEntryType::Adjustment)->count());
    }

    public function test_manual_upload_from_report_center()
    {
        $this->actingAs($this->admin)->post(route('admin.reports.upload'), [
            'provider_id' => $this->mid->bank_provider_id,
            'report_date' => '2026-09-15',
            'file' => $this->cardaqCsv(),
        ])->assertRedirect()->assertInertiaFlash('toast.type', 'success');

        $this->assertSame(4, $this->mid->operations()->count());
    }
}

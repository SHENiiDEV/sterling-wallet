<?php

namespace Tests\Feature\Settlements;

use App\Enums\ReportStatus;
use App\Enums\ReserveEntryType;
use App\Enums\SettlementLineType;
use App\Enums\SettlementStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\DailyReportTask;
use App\Models\FxRate;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\ReserveLedgerEntry;
use App\Models\Settlement;
use App\Models\User;
use App\Reports\Generation\DailyReportGenerator;
use App\Reports\Generation\ReportDocuments;
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
use Illuminate\Validation\ValidationException;
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

        // Once paid, the transaction hash and an explorer link are on the statement.
        $settlement->update(['status' => SettlementStatus::Settled, 'settled_at' => now(), 'tx_hash' => 'abc123hash']);
        $html = view('settlements.statement', app(SettlementStatementPdf::class)->data($settlement->fresh()))->render();
        $this->assertStringContainsString('abc123hash', $html);
        $this->assertStringContainsString('https://tronscan.org/#/transaction/abc123hash', $html);
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

    public function test_the_operations_file_lists_completed_acquirer_rows_with_the_gateway_id_as_sp_id()
    {
        $task = $this->report();
        app(ReportDocuments::class)->write($task);

        $csv = Storage::disk(config('sterling.reports.disk'))->get($task->fresh()->generated_operations_path);
        $rows = array_map('str_getcsv', array_filter(explode("\n", $csv)));
        $header = array_shift($rows);
        [$provider, $type, $payment, $spId] = [array_search('Provider', $header), array_search('Type', $header), array_search('Payment ID', $header), array_search('sp_id', $header)];

        // No provider names and no Role column; only what the acquirer completed: no declines, no gateway-only rows.
        $this->assertFalse(array_search('Role', $header));
        $this->assertStringNotContainsString($this->mid->bankProvider->name, $csv);
        $this->assertStringNotContainsString($this->mid->gateProvider->name, $csv);
        $completed = $this->mid->operations()->where('role', 'bank')->where('operation_type', '!=', 'decline')->count();
        $this->assertGreaterThan(0, $completed);
        $this->assertCount($completed, $rows);

        // sp_id is the gateway's id of the matched operation.
        $gateIds = $this->mid->operations()->where('role', 'gate')->pluck('payment_id')->all();
        foreach ($rows as $row) {
            $this->assertSame('SterlingPay', $row[$provider]);
            $this->assertNotSame('decline', $row[$type]);
            $this->assertNotSame('', $row[$payment]);
            $this->assertNotContains($row[$payment], $gateIds);
            if ($row[$spId] !== '') {
                $this->assertContains($row[$spId], $gateIds);
            }
        }
        $this->assertNotEmpty(array_filter($rows, fn ($r) => $r[$spId] !== ''));
    }

    public function test_a_draft_can_be_recorded_as_paid_on_a_past_date_without_a_hash()
    {
        $report = $this->report();
        $settlement = app(SettlementService::class)->createDraft($this->merchant, $this->admin);

        // A future date is refused; a past one approves and pays the draft in one step, dated that day.
        $this->actingAs($this->admin)->post(route('admin.settlements.record', $settlement), ['paid_on' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('paid_on');
        $this->actingAs($this->admin)->post(route('admin.settlements.record', $settlement), ['paid_on' => '2026-09-20'])
            ->assertSessionHasNoErrors();

        $settlement->refresh();
        $this->assertSame(SettlementStatus::Settled, $settlement->status);
        $this->assertSame('2026-09-20', $settlement->approved_at->toDateString());
        $this->assertSame('2026-09-20', $settlement->settled_at->toDateString());
        $this->assertSame('2026-09-20', $settlement->created_at->toDateString());
        $this->assertNull($settlement->tx_hash);
        $this->assertStringContainsString($settlement->number, (string) $report->fresh()->lockReason());
    }

    public function test_a_draft_can_switch_between_a_merchant_and_its_client()
    {
        $this->report();
        $client = Client::query()->create(['name' => 'Acme group']);
        $company = Company::factory()->create(['name' => 'Acme Ltd', 'client_id' => $client->id]);
        $this->merchant->update(['company_id' => $company->id]);
        $settlement = app(SettlementService::class)->createDraft($this->merchant, $this->admin);
        $this->assertSame(1, $settlement->lines()->where('type', SettlementLineType::Report)->count());

        // Merchant → client: nothing is lost, the scope widens.
        $this->actingAs($this->admin)->post(route('admin.settlements.scope', $settlement), ['client' => $client->id])->assertSessionHasNoErrors();
        $settlement->refresh();
        $this->assertSame($client->id, $settlement->client_id);
        $this->assertSame(1, $settlement->lines()->count());

        // Client → another merchant: this merchant's report drops out and is free again.
        $other = $this->merchantWithTariff();
        $this->actingAs($this->admin)->post(route('admin.settlements.scope', $settlement), ['merchant' => $other->public_id])->assertSessionHasNoErrors();
        $settlement->refresh();
        $this->assertNull($settlement->client_id);
        $this->assertSame($other->id, $settlement->merchant_id);
        $this->assertSame(0, $settlement->lines()->count());
        $this->assertCount(1, app(SettlementService::class)->availableReports($this->merchant));

        // Only a draft can be changed.
        $settlement->update(['status' => SettlementStatus::Approved]);
        $this->actingAs($this->admin)->post(route('admin.settlements.scope', $settlement), ['client' => $client->id])->assertSessionHasErrors('settlement');
    }

    public function test_reports_of_another_merchant_can_be_added_on_purpose_to_a_draft()
    {
        $report = $this->report();
        $other = $this->merchantWithTariff();
        $settlement = app(SettlementService::class)->createDraft($other, $this->admin);
        $this->assertSame(0, $settlement->lines()->count());

        // Without saying so, a report of a merchant outside the scope is refused.
        $this->actingAs($this->admin)->post(route('admin.settlements.lines.store', $settlement), ['report_ids' => [$report->id]]);
        $this->assertSame(0, $settlement->lines()->count());

        // The page offers that merchant's reports, and adding them on purpose works.
        $this->actingAs($this->admin)->get(route('admin.settlements.show', ['settlement' => $settlement, 'from_merchant' => $this->merchant->public_id]))
            ->assertInertia(fn (Assert $page) => $page->has('availableReports', 1));
        $this->actingAs($this->admin)->post(route('admin.settlements.lines.store', $settlement), ['report_ids' => [$report->id], 'from_merchant' => $this->merchant->public_id])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $settlement->lines()->count());
        $this->assertSame($report->id, $settlement->lines()->sole()->daily_report_task_id);
    }

    public function test_a_zero_report_can_be_taken_out_of_a_paid_settlement_but_a_paid_one_cannot()
    {
        $report = $this->report();
        $settlement = app(SettlementService::class)->createDraft($this->merchant, $this->admin);
        $line = $settlement->lines()->where('type', SettlementLineType::Report)->sole();
        app(SettlementService::class)->recordPaid($settlement, $this->admin, CarbonImmutable::parse('2026-09-20'));

        // The line paid real money: refused, nothing changes.
        $this->actingAs($this->admin)->post(route('admin.settlements.lines.detach', [$settlement, $line]))->assertSessionHasErrors('settlement');
        $this->assertSame(1, $settlement->lines()->count());

        // A line that paid 0.00 (the report had no data yet when it was paid) can be taken out: the report is free again.
        $line->update(['amount' => '0', 'amount_payout' => '0']);
        $this->actingAs($this->admin)->post(route('admin.settlements.lines.detach', [$settlement, $line]), ['reason' => 'No bank file yet'])->assertSessionHasNoErrors();

        $this->assertSame(0, $settlement->lines()->where('type', SettlementLineType::Report)->count());
        $this->assertSame(SettlementStatus::Settled, $settlement->fresh()->status);
        $this->assertNull($report->fresh()->lockReason());
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'settlement.line_detached']);
    }

    public function test_a_paid_report_is_corrected_and_its_documents_rebuilt_without_touching_the_settlement()
    {
        $report = $this->report();
        $right = (string) $report->getRawOriginal('net_payout');
        $settlement = app(SettlementService::class)->createDraft($this->merchant, $this->admin);
        $line = $settlement->lines()->where('type', SettlementLineType::Report)->sole();
        app(SettlementService::class)->recordPaid($settlement, $this->admin, CarbonImmutable::parse('2026-09-20'));
        // It was calculated too early and paid with a payout that is too low.
        $report->update(['net_payout' => '100']);

        $this->artisan('reports:correct', ['--mid' => $this->mid->mid])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertTrue(BigDecimal::of($report->fresh()->getRawOriginal('net_payout'))->isEqualTo('100'));

        $this->artisan('reports:correct', ['--mid' => $this->mid->mid, '--apply' => true])->expectsOutputToContain('Corrected 1 report(s)')->assertSuccessful();

        $this->assertTrue(BigDecimal::of($report->fresh()->getRawOriginal('net_payout'))->isEqualTo($right), "Expected {$right}, got ".$report->fresh()->getRawOriginal('net_payout').' / '.$report->fresh()->status->value);
        // What was paid stays as paid.
        $this->assertSame(SettlementStatus::Settled, $settlement->fresh()->status);
        $this->assertTrue(BigDecimal::of($line->fresh()->amount)->isEqualTo($right));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'report.corrected']);
    }

    public function test_an_approved_settlement_can_be_marked_paid_on_a_past_date()
    {
        $this->report();
        $settlement = app(SettlementService::class)->createDraft($this->merchant, $this->admin);
        app(SettlementService::class)->approve($settlement, $this->admin);

        $this->actingAs($this->admin)->post(route('admin.settlements.settle', $settlement), ['paid_on' => '2026-09-18', 'tx_hash' => '0xold'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-09-18', $settlement->fresh()->settled_at->toDateString());
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

        // The list hides the cancelled one until it is asked for.
        $this->get(route('admin.settlements.index'))
            ->assertInertia(fn (Assert $page) => $page->where('cancelledCount', 1)->has('settlements.data', 2));
        $this->get(route('admin.settlements.index', ['cancelled' => 1]))
            ->assertInertia(fn (Assert $page) => $page->has('settlements.data', 3));
        $this->get(route('admin.settlements.index', ['status' => 'cancelled']))
            ->assertInertia(fn (Assert $page) => $page->has('settlements.data', 1));
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

    public function test_a_settlement_can_be_recounted_from_the_operations()
    {
        $this->report();
        $settlement = app(SettlementService::class)->createDraft($this->merchant, $this->admin);

        $this->artisan('settlements:verify', ['settlement' => $settlement->number])
            ->expectsOutputToContain('Everything checks out.')
            ->assertSuccessful();

        // A report that moved after it was put in the settlement is shown as a difference.
        DailyReportTask::query()->sole()->update(['net_payout' => '1.00']);
        $this->artisan('settlements:verify', ['settlement' => (string) $settlement->id])->assertFailed();
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
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/settlements/show')
                ->has('lines', 1)
                ->has('wallets', 1)
                ->has('overview.companies', 1)
                ->where('overview.companies.0.merchants.0.mids.0.reports', 1)
                ->where('overview.wallet.currency', 'USDC'));
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

    public function test_settlement_charge_is_deducted_once_per_settlement()
    {
        $this->merchant->update(['fee_settlement_fixed' => 60]);
        $this->report();
        $service = app(SettlementService::class);

        $settlement = $service->createDraft($this->merchant, $this->admin);

        $fee = $settlement->lines()->where('type', SettlementLineType::Fee)->sole();
        $this->assertMoney('-60', $fee->amount);
        $this->assertSame('EUR', $fee->currency);

        // Recalculating does not stack the charge, and it cannot be removed by hand.
        $service->recalculate($settlement);
        $this->assertSame(1, $settlement->lines()->where('type', SettlementLineType::Fee)->count());
        $this->expectException(ValidationException::class);
        $service->removeLine($fee);
    }

    public function test_empty_settlement_has_no_charge()
    {
        $this->merchant->update(['fee_settlement_fixed' => 60]);

        $settlement = app(SettlementService::class)->createDraft($this->merchant, $this->admin);

        $this->assertSame(0, $settlement->lines()->count());
    }
}

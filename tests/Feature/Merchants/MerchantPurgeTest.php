<?php

namespace Tests\Feature\Merchants;

use App\Enums\MerchantStatus;
use App\Models\DailyReportTask;
use App\Models\Document;
use App\Models\FxRate;
use App\Models\IntegrationAccount;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\ReserveLedgerEntry;
use App\Models\Settlement;
use App\Models\User;
use App\Reports\Ingestion\ReportIngestionService;
use App\Settlements\SettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class MerchantPurgeTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private User $admin;

    private Merchant $gone;

    private Merchant $kept;

    private MerchantMid $goneMid;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        $this->admin = User::factory()->superAdmin()->create();
        FxRate::query()->create(['rate_date' => '2026-09-01', 'base' => 'EUR', 'quote' => 'USDC', 'rate' => '1.10']);

        $cardaq = $this->cardaq();
        $corefy = $this->corefy();
        $this->gone = $this->merchantWithTariff(['name' => 'Gone Ltd']);
        $this->kept = $this->merchantWithTariff(['name' => 'Kept Ltd']);
        $this->goneMid = $this->mid($this->gone, $cardaq, $corefy);
        $this->mid($this->kept, $cardaq, $corefy, ['mid' => '4400000002', 'gate_mid' => 'coma_TEST2']);

        $ingestion = app(ReportIngestionService::class);
        $day = CarbonImmutable::parse('2026-09-15');
        foreach ([['4400000001', 'coma_TEST1'], ['4400000002', 'coma_TEST2']] as [$mid, $account]) {
            $ingestion->ingest($cardaq, $this->cardaqCsv($mid), $day);
            $ingestion->ingest($corefy, $this->corefyCsv($account), $day);
        }

        // A paid settlement with proof of payment.
        $this->gone->wallets()->create(['type' => 'provider_inflow', 'currency' => 'USDC', 'network' => 'TRC20', 'address' => 'TXgone']);
        $service = app(SettlementService::class);
        $settlement = $service->approve($service->createDraft($this->gone, $this->admin), $this->admin);
        Storage::disk('local')->put('settlements/proof.pdf', 'pdf');
        $service->markSettled($settlement, $this->admin, '0xpaid', 'settlements/proof.pdf');
    }

    public function test_only_a_closed_merchant_can_be_deleted_and_the_name_must_match()
    {
        $this->actingAs($this->admin)->delete(route('admin.merchants.destroy', $this->gone), ['confirm' => 'Gone Ltd'])
            ->assertSessionHasErrors('merchant');

        $this->gone->update(['status' => MerchantStatus::Closed]);
        $this->actingAs($this->admin)->delete(route('admin.merchants.destroy', $this->gone), ['confirm' => 'Gone'])
            ->assertSessionHasErrors('confirm');

        $this->assertNotNull($this->gone->fresh());
    }

    public function test_the_dialog_shows_what_will_be_deleted()
    {
        Document::factory()->create(['merchant_id' => $this->gone->id, 'company_id' => $this->gone->company_id]);

        $operations = MerchantOperation::query()->where('merchant_mid_id', $this->goneMid->id)->count();
        $this->assertGreaterThan(0, $operations);

        $this->actingAs($this->admin)->get(route('admin.merchants.show', $this->gone))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('deletion')
                ->reloadOnly('deletion', fn (Assert $reload) => $reload->where('deletion', [
                    'mids' => 1, 'operations' => $operations, 'daily_reports' => 1, 'settlements' => 1, 'paid_settlements' => 1,
                    'reserve_entries' => 1, 'wallets' => 1, 'bank_links' => 0, 'documents_kept' => 1,
                ])));
    }

    public function test_deleting_removes_everything_of_the_merchant_and_nothing_else()
    {
        $report = DailyReportTask::query()->where('merchant_id', $this->gone->id)->sole();
        $keptReport = DailyReportTask::query()->where('merchant_id', $this->kept->id)->sole();
        $document = Document::factory()->create(['merchant_id' => $this->gone->id, 'company_id' => $this->gone->company_id]);
        $onlyGone = IntegrationAccount::query()->create([
            'provider_id' => $this->goneMid->bank_provider_id, 'connector' => 'cardaq-export', 'name' => 'Only gone', 'mid_ids' => [$this->goneMid->id],
        ]);
        $keptOperations = MerchantOperation::query()->where('merchant_id', $this->kept->id)->count();
        $this->gone->update(['status' => MerchantStatus::Closed]);

        $this->actingAs($this->admin)->delete(route('admin.merchants.destroy', $this->gone), ['confirm' => ' Gone Ltd '])
            ->assertRedirect(route('admin.merchants.index'));

        $this->assertNull($this->gone->fresh());
        $this->assertSame(0, MerchantOperation::query()->where('merchant_mid_id', $this->goneMid->id)->orWhere('merchant_id', $this->gone->id)->count());
        $this->assertSame(0, Settlement::query()->count());
        $this->assertSame(0, ReserveLedgerEntry::query()->where('merchant_id', $this->gone->id)->count());
        Storage::disk('local')->assertMissing($report->generated_pdf_path);
        Storage::disk('local')->assertMissing('settlements/proof.pdf');

        // The other merchant is untouched.
        $this->assertGreaterThan(0, $keptOperations);
        $this->assertSame($keptOperations, MerchantOperation::query()->where('merchant_id', $this->kept->id)->count());
        Storage::disk('local')->assertExists($keptReport->generated_pdf_path);

        // Documents stay with the company; a bot limited to the deleted MID is switched off.
        $this->assertNull($document->fresh()->merchant_id);
        $this->assertFalse($onlyGone->fresh()->is_active);
        $this->assertNull($onlyGone->fresh()->mid_ids);
    }
}

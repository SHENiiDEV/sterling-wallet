<?php

namespace Tests\Feature\Reports;

use App\Enums\OperationType;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Models\User;
use App\Reports\Ingestion\ReportIngestionService;
use App\Reports\Reconciliation\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class ManualPairingTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private User $admin;

    private Provider $bank;

    private Provider $gate;

    private MerchantMid $mid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->superAdmin()->create();
        $this->bank = $this->cardaq();
        $this->gate = $this->corefy();
        $this->mid = $this->mid($this->merchantWithTariff(), $this->bank, $this->gate);
    }

    private function operation(Provider $provider, string $payment, string $amount, string $time, array $raw = []): MerchantOperation
    {
        return MerchantOperation::query()->create([
            'merchant_id' => $this->mid->merchant_id, 'merchant_mid_id' => $this->mid->id, 'provider_id' => $provider->id,
            'role' => $provider->type, 'mid' => $this->mid->mid, 'payment_id' => $payment, 'operation_type' => OperationType::Sale,
            'trn_type' => 'sale', 'ips' => 'visa', 'amount' => $amount, 'currency' => 'EUR',
            'transaction_at' => "2026-10-20 {$time}", 'report_date' => '2026-10-20', 'raw' => $raw ?: null,
        ]);
    }

    public function test_an_unpaired_row_offers_candidates_and_can_be_paired_and_unpaired_by_hand()
    {
        $bank = $this->operation($this->bank, 'B-1', '100.00', '10:00:00');
        $near = $this->operation($this->gate, 'G-NEAR', '100.00', '10:20:00');
        $far = $this->operation($this->gate, 'G-FAR', '100.00', '16:00:00');
        $other = $this->operation($this->gate, 'G-OTHER', '55.00', '10:05:00');

        // Same amount only by default, the closest in time first.
        $this->actingAs($this->admin)->get(route('admin.operations.show', $bank))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pair', null)
                ->has('candidates', 2)
                ->where('candidates.0.payment_id', 'G-NEAR')
                ->where('candidates.1.payment_id', 'G-FAR'));

        $this->get(route('admin.operations.show', [$bank, 'all' => 1]))
            ->assertInertia(fn (Assert $page) => $page->has('candidates', 3)->where('allAmounts', true));

        $this->post(route('admin.operations.pair', $bank), ['partner_id' => $near->id])->assertSessionHasNoErrors();
        $this->assertSame($near->id, $bank->fresh()->matched_operation_id);
        $this->assertSame($bank->id, $near->fresh()->matched_operation_id);
        $this->assertSame('G-NEAR', $bank->fresh()->sp_id);
        $this->assertSame('B-1', $near->fresh()->sp_id);

        // A paired row is not offered again; the other candidates stay.
        $this->get(route('admin.operations.show', $far))
            ->assertInertia(fn (Assert $page) => $page->has('candidates', 0));

        $this->delete(route('admin.operations.unpair', $bank))->assertSessionHasNoErrors();
        $this->assertNull($bank->fresh()->matched_operation_id);
        $this->assertNull($near->fresh()->matched_operation_id);
        $this->assertNull($near->fresh()->sp_id);
        $this->assertNull($other->fresh()->matched_operation_id);
    }

    public function test_two_acquirer_rows_or_an_already_paired_row_cannot_be_paired()
    {
        $bank = $this->operation($this->bank, 'B-1', '100.00', '10:00:00');
        $bank2 = $this->operation($this->bank, 'B-2', '100.00', '10:01:00');
        $gate = $this->operation($this->gate, 'G-1', '100.00', '10:02:00');
        $gate2 = $this->operation($this->gate, 'G-2', '100.00', '10:03:00');

        $this->actingAs($this->admin)->post(route('admin.operations.pair', $bank), ['partner_id' => $bank2->id])->assertSessionHasErrors('pair');

        $this->post(route('admin.operations.pair', $bank), ['partner_id' => $gate->id])->assertSessionHasNoErrors();
        $this->post(route('admin.operations.pair', $bank2), ['partner_id' => $gate->id])->assertSessionHasErrors('pair');
        $this->post(route('admin.operations.pair', $bank), ['partner_id' => $gate2->id])->assertSessionHasErrors('pair');
    }

    public function test_a_cascaded_payment_shows_its_attempts()
    {
        $cascaded = $this->operation($this->gate, 'G-C', '10.00', '09:00:00', ['tries_count' => '2']);
        $plain = $this->operation($this->gate, 'G-P', '11.00', '09:00:00', ['tries_count' => '1']);

        $this->actingAs($this->admin)->get(route('admin.operations.show', $cascaded))
            ->assertInertia(fn (Assert $page) => $page->where('operation.attempts', 2));
        $this->get(route('admin.operations.show', $plain))
            ->assertInertia(fn (Assert $page) => $page->where('operation.attempts', 1));
    }

    public function test_rows_four_hours_apart_still_pair_by_card_and_amount()
    {
        // The clocks changed in the Riga night: the bank's times are an hour further off than usual.
        foreach ([[$this->bank, 'B-1', '2026-10-20 14:00:30'], [$this->gate, 'G-1', '2026-10-20 10:00:00']] as [$provider, $payment, $at]) {
            MerchantOperation::query()->create([
                'merchant_id' => $this->mid->merchant_id, 'merchant_mid_id' => $this->mid->id, 'provider_id' => $provider->id,
                'role' => $provider->type, 'mid' => $this->mid->mid, 'payment_id' => $payment, 'operation_type' => OperationType::Sale,
                'trn_type' => 'sale', 'ips' => 'visa', 'amount' => '75.00', 'currency' => 'EUR', 'card_bin' => '535456', 'card_last4' => '8431',
                'transaction_at' => $at, 'report_date' => '2026-10-20',
            ]);
        }

        $this->assertSame(1, app(ReconciliationService::class)->reconcile($this->mid, '2026-10-20', '2026-10-20'));
    }

    public function test_a_gateway_sale_without_its_bank_file_is_waiting_until_the_file_is_due_then_it_is_a_missing_file()
    {
        Storage::fake('local');
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-22 10:00:00', 'Europe/Riga'));

        // Tuesday the 15th: its bank file was due on Thursday the 17th (T+2). Monday the 21st: due Wednesday the 23rd.
        $this->operation($this->gate, 'G-OLD', '10.00', '10:00:00')->update(['report_date' => '2026-09-15']);
        $this->operation($this->gate, 'G-NEW', '11.00', '10:00:00')->update(['report_date' => '2026-09-21']);

        $keys = fn () => collect($this->actingAs($this->admin)->get(route('admin.dashboard'))->viewData('page')['props']['reportAlerts'])->keyBy('key');

        $this->assertSame(1, $keys()['bank_files_missing']['count']);
        $this->assertArrayNotHasKey('unpaired_operations', $keys()->all());

        // Once the bank file is in, a sale still without a pair is a pairing problem.
        app(ReportIngestionService::class)->ingest($this->bank, $this->cardaqCsv(day: '2026-09-15'), CarbonImmutable::parse('2026-09-15'));
        $this->assertArrayHasKey('unpaired_operations', $keys()->all());
    }
}

<?php

namespace Tests\Feature\Reports;

use App\Enums\OperationType;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

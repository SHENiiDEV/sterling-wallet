<?php

namespace Tests\Feature\Reports;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Reports\Reconciliation\ReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private Provider $bank;

    private Provider $gate;

    private MerchantMid $mid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bank = $this->cardaq();
        $this->gate = $this->corefy();
        $this->mid = $this->mid($this->merchantWithTariff(), $this->bank, $this->gate);
    }

    private function op(ProviderType $role, string $id, string $at, array $attributes = []): MerchantOperation
    {
        return MerchantOperation::query()->create([
            'merchant_id' => $this->mid->merchant_id,
            'merchant_mid_id' => $this->mid->id,
            'provider_id' => $role === ProviderType::Bank ? $this->bank->id : $this->gate->id,
            'role' => $role,
            'payment_id' => $id,
            'operation_type' => OperationType::Sale,
            'amount' => '100.00',
            'currency' => 'EUR',
            'card_bin' => '411111',
            'card_last4' => '1111',
            'report_date' => '2026-09-15',
            'transaction_at' => CarbonImmutable::parse($at, 'UTC'),
            ...$attributes,
        ]);
    }

    private function reconcile(): int
    {
        return app(ReconciliationService::class)->reconcile($this->mid, '2026-09-15', '2026-09-15');
    }

    public function test_strict_key_pairs_both_sides()
    {
        $bank = $this->op(ProviderType::Bank, 'CQ-1', '2026-09-15 10:00:00');
        $gate = $this->op(ProviderType::Gate, 'pi_1', '2026-09-15 10:03:00');

        $this->assertSame(1, $this->reconcile());

        $this->assertSame([$gate->id, 'pi_1'], [$bank->fresh()->matched_operation_id, $bank->fresh()->sp_id]);
        $this->assertSame([$bank->id, 'CQ-1'], [$gate->fresh()->matched_operation_id, $gate->fresh()->sp_id]);
    }

    public function test_fallback_key_is_used_when_last4_is_missing()
    {
        $bank = $this->op(ProviderType::Bank, 'CQ-1', '2026-09-15 10:00:00', ['card_last4' => null]);
        $this->op(ProviderType::Gate, 'pi_1', '2026-09-15 10:01:00');

        $this->assertSame(1, $this->reconcile());
        $this->assertSame('pi_1', $bank->fresh()->sp_id);
    }

    public function test_outside_the_window_there_is_no_pair()
    {
        $this->op(ProviderType::Bank, 'CQ-1', '2026-09-15 10:00:00');
        $this->op(ProviderType::Gate, 'pi_1', '2026-09-15 10:30:00');

        $this->assertSame(0, $this->reconcile());
    }

    public function test_a_whole_hour_timezone_shift_is_tolerated_when_enabled()
    {
        $bank = $this->op(ProviderType::Bank, 'CQ-1', '2026-09-15 10:00:00');
        $this->op(ProviderType::Gate, 'pi_1', '2026-09-15 07:01:00'); // gateway file in a different zone

        $this->assertSame(1, $this->reconcile());
        $this->assertSame('pi_1', $bank->fresh()->sp_id);

        $bank->update(['matched_operation_id' => null, 'sp_id' => null]);
        MerchantOperation::query()->where('payment_id', 'pi_1')->update(['matched_operation_id' => null, 'sp_id' => null]);
        $this->bank->update(['matching' => ['try_timezone_shift' => false]]);
        $this->mid->refresh();

        $this->assertSame(0, $this->reconcile());
    }

    public function test_pairs_are_one_to_one()
    {
        $this->op(ProviderType::Bank, 'CQ-1', '2026-09-15 10:00:00');
        $this->op(ProviderType::Bank, 'CQ-2', '2026-09-15 10:01:00');
        $this->op(ProviderType::Gate, 'pi_1', '2026-09-15 10:00:30');

        $this->assertSame(1, $this->reconcile());
        $this->assertSame(1, MerchantOperation::query()->where('role', ProviderType::Bank)->whereNotNull('matched_operation_id')->count());

        // A second run doesn't re-use the gateway operation.
        $this->assertSame(0, $this->reconcile());
    }

    public function test_email_breaks_a_tie_before_time()
    {
        $bank = $this->op(ProviderType::Bank, 'CQ-1', '2026-09-15 10:00:00', ['customer_email' => 'b@buyer.test']);
        $this->op(ProviderType::Gate, 'pi_closest', '2026-09-15 10:00:10', ['customer_email' => 'a@buyer.test']);
        $this->op(ProviderType::Gate, 'pi_email', '2026-09-15 10:04:00', ['customer_email' => 'b@buyer.test']);

        $this->reconcile();

        $this->assertSame('pi_email', $bank->fresh()->sp_id);
    }

    public function test_closest_time_wins_without_email()
    {
        $bank = $this->op(ProviderType::Bank, 'CQ-1', '2026-09-15 10:00:00');
        $this->op(ProviderType::Gate, 'pi_far', '2026-09-15 10:04:00');
        $this->op(ProviderType::Gate, 'pi_near', '2026-09-15 09:59:30');

        $this->reconcile();

        $this->assertSame('pi_near', $bank->fresh()->sp_id);
    }

    public function test_declines_and_different_types_are_never_paired()
    {
        $this->op(ProviderType::Bank, 'CQ-1', '2026-09-15 10:00:00', ['operation_type' => OperationType::Refund]);
        $this->op(ProviderType::Gate, 'pi_1', '2026-09-15 10:00:00');
        $this->op(ProviderType::Gate, 'pi_2', '2026-09-15 10:00:00', ['operation_type' => OperationType::Decline]);

        $this->assertSame(0, $this->reconcile());
    }

    public function test_the_gateway_side_is_searched_a_few_days_around_the_clearing_date()
    {
        $bank = $this->op(ProviderType::Bank, 'CQ-1', '2026-09-15 10:00:00');
        $this->op(ProviderType::Gate, 'pi_1', '2026-09-15 10:01:00', ['report_date' => '2026-09-13']);

        $this->assertSame(1, $this->reconcile());
        $this->assertSame('pi_1', $bank->fresh()->sp_id);
    }

    public function test_the_command_reconciles_mids_with_unmatched_operations()
    {
        $this->travelTo('2026-09-17 12:00:00');
        $this->op(ProviderType::Bank, 'CQ-1', '2026-09-15 10:00:00');
        $this->op(ProviderType::Gate, 'pi_1', '2026-09-15 10:01:00');

        $this->artisan('reports:reconcile')->expectsOutputToContain('1 new pair(s)')->assertSuccessful();
    }
}

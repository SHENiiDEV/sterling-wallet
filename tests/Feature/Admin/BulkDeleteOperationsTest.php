<?php

namespace Tests\Feature\Admin;

use App\Enums\OperationType;
use App\Models\AdminAuditLog;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class BulkDeleteOperationsTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private User $admin;

    private MerchantMid $mid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->superAdmin()->create();
        $this->mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), $this->corefy());
    }

    private function operation(string $payment, string $currency = 'EUR'): MerchantOperation
    {
        return MerchantOperation::query()->create([
            'merchant_id' => $this->mid->merchant_id, 'merchant_mid_id' => $this->mid->id, 'provider_id' => $this->mid->bank_provider_id,
            'role' => 'bank', 'mid' => $this->mid->mid, 'payment_id' => $payment, 'operation_type' => OperationType::Sale,
            'trn_type' => 'sale', 'amount' => '10.00', 'currency' => $currency, 'transaction_at' => '2026-10-20 10:00:00', 'report_date' => '2026-10-20',
        ]);
    }

    public function test_the_ticked_operations_are_deleted_after_typing_the_count_and_a_reason()
    {
        $a = $this->operation('A');
        $b = $this->operation('B');
        $keep = $this->operation('C');

        $this->actingAs($this->admin)->post(route('admin.operations.bulk-destroy'), [
            'mode' => 'selected', 'ids' => [$a->id, $b->id], 'expected' => 2, 'confirm' => 'DELETE 1', 'reason' => 'wrong file',
        ])->assertSessionHasErrors('confirm');
        $this->assertSame(3, MerchantOperation::query()->count());

        $this->post(route('admin.operations.bulk-destroy'), [
            'mode' => 'selected', 'ids' => [$a->id, $b->id], 'expected' => 2, 'confirm' => 'DELETE 2', 'reason' => 'wrong file',
        ])->assertSessionHasNoErrors();

        $this->assertSame([$keep->id], MerchantOperation::query()->pluck('id')->all());
        $log = AdminAuditLog::query()->where('action', 'operations.bulk_deleted')->sole();
        $this->assertSame([2, 'wrong file'], [$log->meta['deleted'], $log->meta['reason']]);
    }

    public function test_everything_the_filters_match_can_be_deleted_but_never_an_unfiltered_list()
    {
        $this->operation('E1');
        $this->operation('E2');
        $this->operation('G1', 'GBP');
        $this->operation('G2', 'GBP');
        $this->operation('G3', 'GBP');

        // No filter: refused.
        $this->actingAs($this->admin)->post(route('admin.operations.bulk-destroy'), [
            'mode' => 'filtered', 'expected' => 5, 'confirm' => 'DELETE 5', 'reason' => 'x',
        ])->assertSessionHasErrors('mode');

        // The count must be what the filters match now.
        $this->post(route('admin.operations.bulk-destroy'), [
            'mode' => 'filtered', 'currency' => 'GBP', 'expected' => 2, 'confirm' => 'DELETE 2', 'reason' => 'x',
        ])->assertSessionHasErrors('expected');

        $this->post(route('admin.operations.bulk-destroy'), [
            'mode' => 'filtered', 'currency' => 'GBP', 'expected' => 3, 'confirm' => 'DELETE 3', 'reason' => 'GBP test data',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['E1', 'E2'], MerchantOperation::query()->orderBy('payment_id')->pluck('payment_id')->all());
    }

    public function test_deleting_a_paired_operation_frees_its_pair()
    {
        $bank = $this->operation('B-1');
        $gate = MerchantOperation::query()->create([
            'merchant_id' => $this->mid->merchant_id, 'merchant_mid_id' => $this->mid->id, 'provider_id' => $this->mid->gate_provider_id,
            'role' => 'gate', 'mid' => $this->mid->mid, 'payment_id' => 'G-1', 'operation_type' => OperationType::Sale,
            'trn_type' => 'sale', 'amount' => '10.00', 'currency' => 'EUR', 'transaction_at' => '2026-10-20 10:00:00', 'report_date' => '2026-10-20',
            'matched_operation_id' => $bank->id, 'sp_id' => 'B-1',
        ]);
        $bank->update(['matched_operation_id' => $gate->id, 'sp_id' => 'G-1']);

        $this->actingAs($this->admin)->post(route('admin.operations.bulk-destroy'), [
            'mode' => 'selected', 'ids' => [$bank->id], 'expected' => 1, 'confirm' => 'DELETE 1', 'reason' => 'x',
        ])->assertSessionHasNoErrors();

        $this->assertNull($gate->fresh()->matched_operation_id);
    }
}

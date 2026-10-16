<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\DailyReportTask;
use App\Models\MerchantOperation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class OperationManagementTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $midId, int $providerId, array $overrides = []): array
    {
        return [
            'merchant_mid_id' => $midId,
            'provider_id' => $providerId,
            'payment_id' => 'MAN-1',
            'operation_type' => 'sale',
            'report_date' => '2026-09-15',
            'transaction_at' => '2026-09-15 10:30:00',
            'amount' => '125.50',
            'currency' => 'EUR',
            'card_bin' => '411111',
            'card_last4' => '1111',
            'ips' => 'visa',
            'region' => 'eu',
            'customer_name' => 'Gregor Engel',
            'customer_account' => 'AT562050500001581487',
            'reason' => 'Landed on another terminal',
            ...$overrides,
        ];
    }

    public function test_an_operation_can_be_added_by_hand()
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(), $cardaq, $corefy);

        $this->actingAs($this->admin)->get(route('admin.operations.create'))->assertOk();

        $response = $this->actingAs($this->admin)->post(route('admin.operations.store'), $this->payload($mid->id, $cardaq->id));

        $operation = MerchantOperation::query()->firstOrFail();
        $response->assertRedirect(route('admin.operations.show', $operation));
        $this->assertSame($mid->merchant_id, $operation->merchant_id);
        $this->assertSame('bank', $operation->role->value);
        $this->assertSame($mid->mid, $operation->mid);
        $this->assertTrue($operation->raw['manual']);
        $this->assertSame('Landed on another terminal', $operation->raw['reason']);
        $this->assertSame('Gregor Engel', $operation->raw['remitterName']);
        $this->assertSame('AT562050500001581487', $operation->raw['remitterAccount']);
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'operation.created')->count());

        $task = DailyReportTask::query()->sole();
        $this->assertSame($mid->id, $task->merchant_mid_id);
        $this->assertSame('2026-09-15', $task->report_date->toDateString());
        $this->assertTrue($task->sources()->where('provider_id', $cardaq->id)->exists());
    }

    public function test_a_provider_outside_the_mid_and_duplicates_are_refused()
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(), $cardaq, $corefy);
        $other = $this->madfin();

        $this->actingAs($this->admin)->post(route('admin.operations.store'), $this->payload($mid->id, $other->id))
            ->assertSessionHasErrors('provider_id');

        $this->actingAs($this->admin)->post(route('admin.operations.store'), $this->payload($mid->id, $cardaq->id));
        $this->actingAs($this->admin)->post(route('admin.operations.store'), $this->payload($mid->id, $cardaq->id))
            ->assertSessionHasErrors('payment_id');

        $this->assertSame(1, MerchantOperation::query()->count());
    }

    public function test_deleting_needs_the_payment_id_and_a_reason()
    {
        [$cardaq, $corefy] = [$this->cardaq(), $this->corefy()];
        $mid = $this->mid($this->merchantWithTariff(), $cardaq, $corefy);
        $this->actingAs($this->admin)->post(route('admin.operations.store'), $this->payload($mid->id, $cardaq->id));
        $operation = MerchantOperation::query()->firstOrFail();

        $this->actingAs($this->admin)->delete(route('admin.operations.destroy', $operation), ['confirm' => 'nope', 'reason' => 'Wrong terminal'])
            ->assertSessionHasErrors('confirm');
        $this->actingAs($this->admin)->delete(route('admin.operations.destroy', $operation), ['confirm' => 'MAN-1'])
            ->assertSessionHasErrors('reason');
        $this->assertSame(1, MerchantOperation::query()->count());

        $this->actingAs($this->admin)->delete(route('admin.operations.destroy', $operation), ['confirm' => 'MAN-1', 'reason' => 'Wrong terminal'])
            ->assertRedirect(route('admin.operations.index'));

        $this->assertSame(0, MerchantOperation::query()->count());
        $log = AdminAuditLog::query()->where('action', 'operation.deleted')->firstOrFail();
        $this->assertSame('Wrong terminal', $log->meta['reason']);
        $this->assertSame('MAN-1', $log->meta['snapshot']['payment_id']);
    }
}

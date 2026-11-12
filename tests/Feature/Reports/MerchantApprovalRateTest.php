<?php

namespace Tests\Feature\Reports;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Enums\UserRole;
use App\Models\MerchantOperation;
use App\Models\User;
use App\Reports\Generation\MerchantApprovalRate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class MerchantApprovalRateTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    public function test_the_approval_rate_is_the_gateways_approved_sales_over_attempts_by_day_week_and_month()
    {
        $merchant = $this->merchantWithTariff();
        $mid = $this->mid($merchant, $this->cardaq(), $corefy = $this->corefy(), ['mid' => '4400000001', 'gate_mid' => 'coma_TEST1']);
        $add = function (string $id, OperationType $type, string $at, ?string $resolution = null) use ($mid, $corefy) {
            MerchantOperation::query()->create([
                'merchant_id' => $mid->merchant_id, 'merchant_mid_id' => $mid->id, 'provider_id' => $corefy->id, 'role' => ProviderType::Gate,
                'payment_id' => $id, 'operation_type' => $type, 'resolution' => $resolution, 'amount' => '10.00', 'currency' => 'EUR',
                'report_date' => substr($at, 0, 10), 'transaction_at' => CarbonImmutable::parse($at, 'UTC'),
            ]);
        };
        // 3 approved and 1 declined on Wed 11 Nov, 1 approved and 1 declined on Tue 3 Nov; a routing error never counts.
        foreach (['a', 'b', 'c'] as $id) {
            $add($id, OperationType::Sale, '2026-11-11 10:00:00');
        }
        $add('d', OperationType::Decline, '2026-11-11 11:00:00', 'do not honor');
        $add('e', OperationType::Decline, '2026-11-11 12:00:00', 'ROUTING_ERROR');
        $add('f', OperationType::Sale, '2026-11-03 10:00:00');
        $add('g', OperationType::Decline, '2026-11-03 10:30:00', 'insufficient funds');

        $now = CarbonImmutable::parse('2026-11-12 09:00:00');
        $service = app(MerchantApprovalRate::class);

        $days = $service->for($merchant, 'day', $now);
        $this->assertCount(30, $days['rows']);
        $wednesday = collect($days['rows'])->firstWhere('from', '2026-11-11');
        $this->assertSame([4, 3, 1, 75.0], [$wednesday['attempts'], $wednesday['approved'], $wednesday['declined'], $wednesday['rate']]);
        $this->assertSame(1, $days['routing_errors']);
        $this->assertNull(collect($days['rows'])->firstWhere('from', '2026-11-12')['rate']);

        $weeks = $service->for($merchant, 'week', $now);
        $this->assertCount(12, $weeks['rows']);
        $this->assertSame([4, 3, 75.0], [$weeks['rows'][11]['attempts'], $weeks['rows'][11]['approved'], $weeks['rows'][11]['rate']]);
        $this->assertSame([2, 1, 50.0], [$weeks['rows'][10]['attempts'], $weeks['rows'][10]['approved'], $weeks['rows'][10]['rate']]);

        $months = $service->for($merchant, 'month', $now);
        $this->assertCount(12, $months['rows']);
        $this->assertSame([6, 4], [$months['totals']['attempts'], $months['totals']['approved']]);
        $this->assertSame(66.7, $months['totals']['rate']);

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->actingAs($admin)->get(route('admin.merchants.show', ['merchant' => $merchant, 'approval' => 'day']))
            ->assertOk()->assertInertia(fn ($page) => $page->where('approval.group', 'day')->has('approval.rows', 30));
    }
}

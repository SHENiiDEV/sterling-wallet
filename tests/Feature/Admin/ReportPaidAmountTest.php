<?php

namespace Tests\Feature\Admin;

use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\DailyReportTask;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class ReportPaidAmountTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    public function test_the_merchants_daily_reports_show_what_was_paid_in_crypto_from_the_settlement_line()
    {
        $merchant = $this->merchantWithTariff();
        $mid = $this->mid($merchant, $this->cardaq(), $this->corefy(), ['mid' => '4400000001', 'gate_mid' => 'coma_TEST1']);
        $make = fn (string $day) => DailyReportTask::query()->create([
            'merchant_id' => $merchant->id, 'merchant_mid_id' => $mid->id, 'report_date' => $day,
            'period_from' => $day, 'period_to' => $day, 'currency' => 'EUR', 'status' => ReportStatus::Completed,
        ]);
        $paid = $make('2026-11-10');
        $draft = $make('2026-11-11');
        $none = $make('2026-11-12');

        $line = fn (Settlement $s, DailyReportTask $r, string $amount) => $s->lines()->create([
            'type' => 'report', 'daily_report_task_id' => $r->id, 'description' => 'r', 'currency' => 'EUR',
            'amount' => '1000.00', 'rate' => '1.00000000', 'amount_payout' => $amount,
        ]);
        $settled = Settlement::query()->create(['number' => 'SET-P-1', 'merchant_id' => $merchant->id, 'status' => 'settled', 'payout_currency' => 'USDT', 'rates' => []]);
        $line($settled, $paid, '1234.5600');
        $open = Settlement::query()->create(['number' => 'SET-P-2', 'merchant_id' => $merchant->id, 'status' => 'draft', 'payout_currency' => 'USDC', 'rates' => []]);
        $line($open, $draft, '999.0000');

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->actingAs($admin)->get(route('admin.merchants.show', $merchant))->assertOk()
            ->assertInertia(function ($page) use ($paid, $draft, $none) {
                $rows = collect($page->toArray()['props']['reports'])->keyBy('id');
                $this->assertSame('SET-P-1', $rows[$paid->id]['paid']['number']);
                $this->assertSame('USDT', $rows[$paid->id]['paid']['currency']);
                $this->assertTrue($rows[$paid->id]['paid']['settled']);
                $this->assertEqualsWithDelta(1234.56, (float) $rows[$paid->id]['paid']['amount'], 0.001);
                $this->assertNull($rows[$draft->id]['paid']);
                $this->assertNull($rows[$none->id]['paid']);
            });
    }
}

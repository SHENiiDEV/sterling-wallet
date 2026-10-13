<?php

namespace Tests\Feature\Api;

use App\Enums\MidStatus;
use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Models\DailyReportTask;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Provider;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class TransferReportApiTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        config(['sterling.bot_api_key' => 'secret-token']);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 09:00:00', 'UTC'));
    }

    private function lapkipay(): Provider
    {
        return Provider::factory()->create([
            'name' => 'LapkiPay', 'code' => 'lapkipay', 'type' => ProviderType::Bank, 'report_format' => 'lapkipay',
            'timezone' => 'UTC', 'report_delay_days' => 0,
            'cost_visa_eu_percent' => null, 'cost_visa_non_eu_percent' => null, 'cost_mastercard_eu_percent' => null, 'cost_mastercard_non_eu_percent' => null,
            'cost_acq_eu_percent' => 0, 'cost_acq_non_eu_percent' => 0,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function batch(): array
    {
        $tx = fn (string $id, float $amount, string $status = 'Completed') => [
            'id' => $id, 'company' => 'BASILDON LIMITED', 'name' => 'Marina Levencova', 'type' => 'Incoming transfer',
            'fromAccount' => 'LT133250019897585876', 'bicSwift' => 'REVOLT21XXX', 'amount' => $amount, 'currency' => 'EUR',
            'status' => $status, 'reference' => 'Sent from Revolut', 'date' => '12/10/2026 17:21:00',
        ];

        return [$tx('TR1', 100.00), $tx('TR2', 200.00), $tx('TR3', 50.00, 'Pending')];
    }

    private function sendBatch(array $payload, string $code = 'lapkipay')
    {
        return $this->postJson("/api/v1/transfers/{$code}", $payload, ['Authorization' => 'Bearer secret-token']);
    }

    private function assertMoney(string $expected, mixed $actual): void
    {
        $this->assertTrue(BigDecimal::of((string) $actual)->isEqualTo($expected), "Expected {$expected}, got {$actual}.");
    }

    public function test_the_batch_needs_the_bot_token()
    {
        $this->lapkipay();

        $this->postJson('/api/v1/transfers/lapkipay', ['transactions' => $this->batch()])->assertUnauthorized();
    }

    public function test_only_completed_incoming_transfers_are_kept_and_a_new_mid_is_parked()
    {
        $this->lapkipay();

        $this->sendBatch(['transactions' => $this->batch()])->assertOk()->assertJsonPath('rows', 2)->assertJsonPath('unknown_mids.0', 'BASILDON LIMITED EUR');

        $mid = MerchantMid::query()->where('mid', 'BASILDON LIMITED EUR')->sole();
        $this->assertSame(MidStatus::Review, $mid->status);
        $this->assertSame(2, MerchantOperation::query()->count());
        $this->assertSame('2026-10-12', MerchantOperation::query()->first()->report_date->toDateString());

        // The same batch again, with the pending transfer completed, adds only that one.
        $second = $this->batch();
        $second[2]['status'] = 'Completed';
        $this->sendBatch(['transactions' => $second])->assertOk();
        $this->assertSame(3, MerchantOperation::query()->count());
    }

    public function test_a_transfer_report_uses_the_transfer_rate_and_needs_no_card_rates()
    {
        $merchant = $this->merchantWithTariff([
            'fee_visa_eu_percent' => null, 'fee_visa_non_eu_percent' => null, 'fee_mastercard_eu_percent' => null, 'fee_mastercard_non_eu_percent' => null,
            'fee_acq_eu_percent' => 0, 'fee_acq_non_eu_percent' => 0,
            'fee_transfer_percent' => 1.5, 'fee_success_fixed' => 0.30, 'fee_fiat_to_crypto_percent' => 0.15,
        ]);
        $bank = $this->lapkipay();
        $this->mid($merchant, $bank, null, ['mid' => 'BASILDON LIMITED EUR']);

        $this->sendBatch(['transactions' => $this->batch()])->assertOk();

        $task = DailyReportTask::query()->sole();
        $this->assertSame(ReportStatus::Completed, $task->status, (string) $task->error_log);
        $this->assertMoney('300.00', $task->turnover);          // 100 + 200, the pending 50 is not counted
        $this->assertMoney('5.10', $task->total_merchant_fee);  // 1.5% × 300 + 2 × 0.30
        $this->assertMoney('29.49', $task->reserve_amount);     // 10% × (300 − 5.10)
        $this->assertMoney('265.41', $task->net_volume);
        $this->assertMoney('0.40', $task->conversion_fee);      // 0.15% × 265.41
        $this->assertMoney('265.01', $task->net_payout);
        $this->assertSame('transfer_unknown', array_key_first(array_filter($task->summary_data['by_scheme'], fn ($g) => $g['count'] > 0)));
    }

    public function test_balances_are_stored_on_the_matching_mid()
    {
        $merchant = $this->merchantWithTariff(['fee_transfer_percent' => 1.5]);
        $mid = $this->mid($merchant, $this->lapkipay(), null, ['mid' => 'BASILDON LIMITED EUR']);

        $this->sendBatch([
            'transactions' => $this->batch(),
            'balances' => [['company' => 'Basildon Limited', 'currency' => 'EUR', 'available' => 5.0, 'hold' => 0]],
        ])->assertOk()->assertJsonPath('balances_updated', 1);

        $this->assertMoney('5.00', $mid->fresh()->reported_balance);
        $this->assertNotNull($mid->fresh()->balance_reported_at);
    }

    public function test_a_provider_without_the_transfer_format_is_refused()
    {
        $this->cardaq();

        $this->sendBatch(['transactions' => $this->batch()], 'cardaq')->assertStatus(422);
    }
}

<?php

namespace Tests\Feature\Reports;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\MerchantOperation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class DedupeOperationsTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    public function test_the_same_payment_stored_twice_keeps_the_fuller_record()
    {
        Queue::fake();
        Http::fake();
        config(['sterling.bots.telegram_token' => 'tg', 'sterling.bots.telegram_chat_id' => '42']);
        $mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), $this->corefy());
        $make = fn (array $attributes) => MerchantOperation::query()->create([
            'merchant_id' => $mid->merchant_id, 'merchant_mid_id' => $mid->id, 'provider_id' => $mid->bank_provider_id, 'role' => ProviderType::Bank,
            'payment_id' => '1220416995', 'operation_type' => OperationType::Sale, 'amount' => '1047.50', 'currency' => 'EUR', 'report_date' => '2026-10-22',
            'transaction_at' => CarbonImmutable::parse('2026-10-22 00:28:28', 'UTC'), ...$attributes,
        ]);
        $poor = $make(['trn_type' => null, 'raw' => ['ARN' => 'x']]);
        $full = $make(['trn_type' => '5', 'raw' => ['ARN' => 'x', 'Processing Date/Time' => '2026-10-23 03:00:00']]);
        $other = $make(['payment_id' => 'UNIQUE', 'trn_type' => '5']);

        // Dry run: only lists, and tells.
        $this->assertSame(0, Artisan::call('operations:dedupe', ['--notify' => true]));
        $this->assertStringContainsString((string) $poor->id, Artisan::output());
        $this->assertSame(3, MerchantOperation::query()->count());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage') && str_contains($request['text'], 'Duplicate payments'));

        // Applied: the fuller record stays, a payment that is there once is not touched.
        $this->assertSame(0, Artisan::call('operations:dedupe', ['--apply' => true]));
        $this->assertSame([$full->id, $other->id], MerchantOperation::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, Artisan::call('operations:dedupe'));
        $this->assertStringContainsString('No payment is stored twice', Artisan::output());
    }
}

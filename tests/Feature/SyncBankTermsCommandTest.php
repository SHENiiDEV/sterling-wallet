<?php

namespace Tests\Feature;

use App\Enums\ProviderType;
use App\Models\Merchant;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncBankTermsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_differences_and_applies_them_with_the_flag()
    {
        $cardaq = Provider::factory()->create(['code' => 'cardaq', 'name' => 'Cardaq', 'type' => ProviderType::Bank, 'cost_success_fixed' => 0.10]);
        Provider::factory()->create(['code' => 'madfin', 'name' => 'Madfin', 'type' => ProviderType::Bank]);
        $merchant = Merchant::factory()->create(['name' => 'WORKING AGENT LTD']);
        $merchant->acquirers()->create(['provider_id' => $cardaq->id, 'limit' => 1000, 'limit_currency' => 'EUR']);

        $this->artisan('banks:sync-terms')->assertSuccessful();
        $this->assertSame('0.1000', $cardaq->fresh()->cost_success_fixed);

        $this->artisan('banks:sync-terms', ['--apply' => true])->assertSuccessful();

        $cardaq->refresh();
        $this->assertSame('0.2000', $cardaq->cost_success_fixed);
        $this->assertNull($cardaq->cost_visa_eu_percent);
        $this->assertSame('3.800', $cardaq->cost_mastercard_eu_percent);
        $this->assertSame('0.0000', $cardaq->settlement_fee);
        $this->assertSame('50000.0000', Provider::query()->where('code', 'madfin')->sole()->rolling_reserve_cap);

        $link = $merchant->acquirers()->sole();
        $this->assertSame('1800000.00', $link->limit);
        $this->assertSame('7372', $link->mcc);
    }
}

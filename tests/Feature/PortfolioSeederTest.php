<?php

namespace Tests\Feature;

use App\Enums\AcquirerStatus;
use App\Enums\MerchantStatus;
use App\Enums\ProviderType;
use App\Models\Merchant;
use App\Models\MerchantAcquirer;
use App\Models\Provider;
use App\Models\User;
use Database\Seeders\PortfolioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PortfolioSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_loads_banks_merchants_and_onboarding_and_is_repeatable()
    {
        // Cardaq may already exist with its own report settings.
        Provider::factory()->create(['code' => 'cardaq', 'name' => 'Cardaq', 'type' => ProviderType::Bank, 'report_format' => 'cardaq', 'connector' => 'cardaq-export', 'report_delay_days' => 3]);

        $this->seed(PortfolioSeeder::class);
        $this->seed(PortfolioSeeder::class);

        $this->assertSame(5, Provider::query()->where('type', ProviderType::Bank)->count());
        $this->assertSame(7, Merchant::query()->count());
        $this->assertSame(17, MerchantAcquirer::query()->count());

        $madfin = Provider::query()->where('code', 'madfin')->sole();
        $this->assertSame(['3.600', '4.000', '0.2500', '60.0000', 'T+2', '10000.0000', '10.000', 180, '60000.0000', '6.0000', '80.0000', 'madfin'], [
            $madfin->cost_visa_eu_percent, $madfin->cost_mastercard_non_eu_percent, $madfin->cost_success_fixed,
            $madfin->settlement_fee, $madfin->settlement_cycle, $madfin->min_settlement, $madfin->rolling_reserve_percent,
            $madfin->rolling_reserve_days, $madfin->rolling_reserve_cap, $madfin->cost_refund_fixed, $madfin->cost_chargeback_fixed,
            $madfin->report_format,
        ]);

        $payally = Provider::query()->where('code', 'payally')->sole();
        $this->assertSame(['0.0500', '0.0500', 'T+2, weekly (Tuesdays)'], [$payally->cost_success_fixed, $payally->cost_decline_fixed, $payally->settlement_cycle]);

        $cardaq = Provider::query()->where('code', 'cardaq')->sole();
        $this->assertNull($cardaq->cost_visa_eu_percent);
        $this->assertSame('3.800', $cardaq->cost_mastercard_eu_percent);
        $this->assertSame(3, $cardaq->report_delay_days); // kept, not overwritten
        $this->assertStringContainsString('Mastercard only', (string) $cardaq->notes);
        $this->assertSame(1, substr_count((string) $cardaq->notes, 'Mastercard only'));

        $this->assertStringContainsString('IC++', (string) Provider::query()->where('code', 'pixxels')->value('notes'));

        $hartwick = Merchant::query()->where('name', 'HARTWICK VENTURES LTD')->sole();
        $this->assertSame(['velusim.com', '4814', MerchantStatus::Onboarding], [$hartwick->website, $hartwick->mcc, $hartwick->status]);
        $limits = $hartwick->acquirers()->with('provider')->get()->mapWithKeys(fn ($a) => [$a->provider->code => (float) $a->limit])->sortKeys()->all();
        $this->assertSame(['madfin' => 350000.0, 'payally' => 2000000.0, 'pixxels' => 500000.0, 'trustpayments' => 500000.0], $limits);

        $fixero = Merchant::query()->where('name', 'FIXERO LTD')->sole();
        $this->assertSame(MerchantStatus::Active, $fixero->status);
        $madfinLink = $fixero->acquirers()->where('provider_id', $madfin->id)->sole();
        $this->assertSame([AcquirerStatus::ActiveMids, 'Deniss'], [$madfinLink->status, $madfinLink->psp]);
        $this->assertSame('Flexify', MerchantAcquirer::query()->whereHas('merchant', fn ($q) => $q->where('name', 'CUR NOVA LTD'))->value('psp'));

        // Shown on the merchant page.
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.merchants.show', $fixero))
            ->assertInertia(fn (Assert $page) => $page->has('acquirers', 3)->where('merchant.website', 'fixero.co.uk'));
    }

    public function test_acquirer_records_can_be_managed_from_the_merchant_page()
    {
        $admin = User::factory()->superAdmin()->create();
        $merchant = Merchant::factory()->create();
        $bank = Provider::factory()->create();

        $this->actingAs($admin)->post(route('admin.merchants.acquirers.store', $merchant), [
            'provider_id' => $bank->id, 'status' => 'kyb_submitted', 'limit' => 250000, 'limit_currency' => 'EUR', 'integration_status' => 'in_progress', 'psp' => 'BazPay',
        ])->assertSessionHasNoErrors();

        $link = $merchant->acquirers()->sole();
        $this->actingAs($admin)->post(route('admin.merchants.acquirers.store', $merchant), [
            'provider_id' => $bank->id, 'status' => 'prepare_kyb', 'limit_currency' => 'EUR', 'integration_status' => 'need_to_do',
        ])->assertSessionHasErrors('provider_id');

        $this->actingAs($admin)->put(route('admin.merchants.acquirers.update', [$merchant, $link]), [
            'provider_id' => $bank->id, 'status' => 'active_mids', 'limit' => 300000, 'limit_currency' => 'EUR', 'integration_status' => 'done',
        ])->assertSessionHasNoErrors();
        $this->assertSame(AcquirerStatus::ActiveMids, $link->fresh()->status);

        $this->actingAs($admin)->delete(route('admin.merchants.acquirers.destroy', [$merchant, $link]))->assertRedirect();
        $this->assertSame(0, $merchant->acquirers()->count());
    }
}

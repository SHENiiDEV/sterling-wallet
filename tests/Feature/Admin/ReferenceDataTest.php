<?php

namespace Tests\Feature\Admin;

use App\Models\BankHoliday;
use App\Models\Company;
use App\Models\FxRate;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_cannot_be_nested_under_its_own_subsidiary()
    {
        $parent = Company::factory()->create();
        $child = Company::factory()->create(['parent_id' => $parent->id]);

        $this->actingAs(User::factory()->create())
            ->put(route('admin.companies.update', $parent), ['name' => $parent->name, 'parent_id' => $child->id])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_descendants_never_include_siblings()
    {
        $root = Company::factory()->create();
        $a = Company::factory()->create(['parent_id' => $root->id]);
        $b = Company::factory()->create(['parent_id' => $root->id]);
        $aChild = Company::factory()->create(['parent_id' => $a->id]);

        $this->assertEqualsCanonicalizing([$a->id, $aChild->id], $a->descendantIdsWithSelf());
        $this->assertNotContains($b->id, $a->descendantIdsWithSelf());
    }

    public function test_company_with_merchants_cannot_be_deleted()
    {
        $merchant = Merchant::factory()->create();

        $this->actingAs(User::factory()->create())
            ->delete(route('admin.companies.destroy', $merchant->company))
            ->assertSessionHasErrors('company');
    }

    public function test_provider_in_use_cannot_be_deleted()
    {
        $provider = Provider::factory()->create();
        MerchantMid::factory()->create(['bank_provider_id' => $provider->id]);

        $this->actingAs(User::factory()->create())
            ->delete(route('admin.providers.destroy', $provider))
            ->assertSessionHasErrors('provider');
    }

    public function test_provider_codes_are_unique()
    {
        Provider::factory()->create(['code' => 'cardaq']);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.providers.store'), [
                'name' => 'Cardaq 2', 'code' => 'cardaq', 'type' => 'bank', 'is_active' => true,
                'cost_acq_eu_percent' => 1, 'cost_acq_non_eu_percent' => 2, 'cost_crypto_percent' => 0, 'rolling_reserve_percent' => 0,
                'cost_success_fixed' => 0, 'cost_decline_fixed' => 0, 'cost_refund_fixed' => 0, 'cost_chargeback_fixed' => 0,
                'settlement_fee' => 0, 'min_settlement' => 0, 'rolling_reserve_cap' => 0, 'rolling_reserve_days' => 180,
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_fx_rate_for_same_day_is_replaced()
    {
        $admin = User::factory()->create();

        foreach (['1.16', '1.17'] as $rate) {
            $this->actingAs($admin)->post(route('admin.fx-rates.store'), [
                'rate_date' => today()->toDateString(), 'base' => 'EUR', 'quote' => 'USDC', 'rate' => $rate,
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame('1.17000000', FxRate::query()->sole()->rate);
    }

    public function test_bank_holiday_dates_are_unique()
    {
        BankHoliday::query()->create(['date' => '2026-08-31', 'name' => 'Summer']);

        $this->actingAs(User::factory()->create())
            ->post(route('admin.bank-holidays.store'), ['date' => '2026-08-31', 'name' => 'Duplicate'])
            ->assertSessionHasErrors('date');
    }

    public function test_admin_pages_render()
    {
        $this->actingAs(User::factory()->create());

        foreach (['admin.companies.index', 'admin.providers.index', 'admin.providers.create', 'admin.merchants.index', 'admin.merchants.create', 'admin.fx-rates.index', 'admin.bank-holidays.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }
}

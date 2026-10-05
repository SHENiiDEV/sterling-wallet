<?php

namespace Tests\Feature\Admin;

use App\Enums\Currency;
use App\Enums\MerchantStatus;
use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Enums\UserRole;
use App\Models\AdminAuditLog;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MerchantTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
    }

    public function test_merchant_is_created_with_public_id()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.merchants.store'), $this->payload(['status' => 'onboarding']))
            ->assertRedirect();

        $merchant = Merchant::query()->sole();
        $this->assertStringStartsWith('mer_', $merchant->public_id);
    }

    public function test_active_merchant_requires_card_tariff()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.merchants.store'), $this->payload([
                'status' => 'active',
                'fee_visa_eu_percent' => null,
            ]))
            ->assertSessionHasErrors('fee_visa_eu_percent');
    }

    public function test_na_scheme_and_collab_fee_are_saved()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.merchants.store'), $this->payload([
                'status' => 'active',
                'fee_visa_non_eu_percent' => 'N/A',
                'fee_collab_fixed' => '1.5',
            ]))
            ->assertSessionHasNoErrors();

        $merchant = Merchant::query()->sole();
        $this->assertNull($merchant->fee_visa_non_eu_percent);
        $this->assertSame('1.5000', $merchant->fee_collab_fixed);
    }

    public function test_test_merchant_may_go_active_without_tariff()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.merchants.store'), $this->payload([
                'status' => 'active',
                'is_test' => true,
                'fee_visa_eu_percent' => null,
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_index_filters_by_mid_currency_and_search()
    {
        $usd = Merchant::factory()->create(['name' => 'Dollar Shop']);
        MerchantMid::factory()->for($usd)->create(['currency' => Currency::Usd, 'mid' => '5550001']);
        $eur = Merchant::factory()->create(['name' => 'Euro Shop']);
        MerchantMid::factory()->for($eur)->create(['currency' => Currency::Eur]);

        $this->actingAs($this->admin)
            ->get(route('admin.merchants.index', ['currency' => 'USD']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('merchants.data', 1)
                ->where('merchants.data.0.name', 'Dollar Shop'));

        $this->get(route('admin.merchants.index', ['search' => '5550001']))
            ->assertInertia(fn (Assert $page) => $page->has('merchants.data', 1));
    }

    public function test_merchant_can_have_mids_in_several_currencies()
    {
        $merchant = Merchant::factory()->create(['status' => MerchantStatus::Onboarding]);
        $bank = Provider::factory()->create();

        foreach (['USD', 'EUR', 'GBP'] as $i => $currency) {
            $this->actingAs($this->admin)
                ->post(route('admin.merchants.mids.store', $merchant), [
                    'mid' => "44000{$i}",
                    'currency' => $currency,
                    'status' => 'active',
                    'bank_provider_id' => $bank->id,
                    'rolling_reserve_limit' => 50000,
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertEqualsCanonicalizing(['USD', 'EUR', 'GBP'], $merchant->mids()->pluck('currency')->map->value->all());

        $this->get(route('admin.merchants.show', $merchant))
            ->assertInertia(fn (Assert $page) => $page->component('admin/merchants/show')->has('merchant.mids', 3));
    }

    public function test_mid_numbers_are_unique()
    {
        $existing = MerchantMid::factory()->create();
        $merchant = Merchant::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.merchants.mids.store', $merchant), [
                'mid' => $existing->mid,
                'currency' => 'EUR',
                'status' => 'inactive',
                'rolling_reserve_limit' => 0,
            ])
            ->assertSessionHasErrors('mid');
    }

    public function test_active_mid_of_live_merchant_needs_acquirer()
    {
        $merchant = Merchant::factory()->create(['status' => MerchantStatus::Active]);

        $this->actingAs($this->admin)
            ->post(route('admin.merchants.mids.store', $merchant), [
                'mid' => '123',
                'currency' => 'EUR',
                'status' => 'active',
                'rolling_reserve_limit' => 0,
            ])
            ->assertSessionHasErrors('bank_provider_id');
    }

    public function test_gateway_cannot_be_used_as_acquirer()
    {
        $merchant = Merchant::factory()->create(['status' => MerchantStatus::Onboarding]);
        $gate = Provider::factory()->gate()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.merchants.mids.store', $merchant), [
                'mid' => '123',
                'currency' => 'EUR',
                'status' => 'inactive',
                'bank_provider_id' => $gate->id,
                'rolling_reserve_limit' => 0,
            ])
            ->assertSessionHasErrors('bank_provider_id');
    }

    public function test_mid_currency_is_locked_once_it_has_operations()
    {
        $mid = MerchantMid::factory()->create(['currency' => Currency::Eur, 'status' => 'inactive']);
        $mid->operations()->create([
            'merchant_id' => $mid->merchant_id,
            'provider_id' => Provider::factory()->create()->id,
            'role' => ProviderType::Bank,
            'operation_type' => OperationType::Sale,
            'amount' => 10,
            'currency' => 'EUR',
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.merchants.mids.update', [$mid->merchant, $mid]), [
                'mid' => $mid->mid,
                'currency' => 'USD',
                'status' => 'inactive',
                'rolling_reserve_limit' => 0,
            ])
            ->assertSessionHasErrors('currency');
    }

    public function test_mids_are_scoped_to_their_merchant()
    {
        $mid = MerchantMid::factory()->create();
        $other = Merchant::factory()->create();

        $this->actingAs($this->admin)
            ->delete(route('admin.merchants.mids.destroy', [$other, $mid]))
            ->assertNotFound();
    }

    public function test_seed_phrase_is_encrypted_and_only_super_admins_can_reveal_it()
    {
        $merchant = Merchant::factory()->create();
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->post(route('admin.merchants.wallets.store', $merchant), [
            'type' => 'provider_inflow',
            'currency' => 'USDT',
            'network' => 'TRC20',
            'address' => 'TXYZ',
            'seed_phrase' => 'alpha beta gamma',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $wallet = $merchant->wallets()->sole();
        $this->assertNotSame('alpha beta gamma', $wallet->getRawOriginal('seed_phrase'));

        $this->actingAs($this->admin)
            ->postJson(route('admin.merchants.wallets.seed', [$merchant, $wallet]))
            ->assertForbidden();

        $this->actingAs($super)
            ->postJson(route('admin.merchants.wallets.seed', [$merchant, $wallet]))
            ->assertOk()
            ->assertJson(['seed_phrase' => 'alpha beta gamma']);

        $this->assertTrue(AdminAuditLog::query()->where('action', 'wallet.seed_viewed')->where('user_id', $super->id)->exists());
    }

    public function test_admin_cannot_set_seed_phrase()
    {
        $merchant = Merchant::factory()->create();

        $this->actingAs($this->admin)->post(route('admin.merchants.wallets.store', $merchant), [
            'type' => 'other',
            'currency' => 'USDT',
            'network' => 'TRC20',
            'address' => 'TABC',
            'seed_phrase' => 'should be ignored',
        ]);

        $this->assertNull($merchant->wallets()->sole()->seed_phrase);
        $this->assertNotSame(UserRole::SuperAdmin, $this->admin->role);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Acme Retail',
            'status' => 'onboarding',
            'is_test' => false,
            'fee_visa_eu_percent' => 3,
            'fee_visa_non_eu_percent' => 4,
            'fee_mastercard_eu_percent' => 3,
            'fee_mastercard_non_eu_percent' => 4,
            'fee_acq_eu_percent' => 3,
            'fee_acq_non_eu_percent' => 4,
            'fee_success_fixed' => 0.1,
            'fee_decline_fixed' => 0,
            'fee_refund_fixed' => 0.5,
            'fee_chargeback_fixed' => 25,
            'fee_fiat_to_crypto_percent' => 0.4,
            'rolling_reserve_percent' => 10,
            'rolling_reserve_days' => 180,
            ...$overrides,
        ];
    }
}

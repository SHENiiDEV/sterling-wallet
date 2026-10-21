<?php

namespace Tests\Feature\Admin;

use App\Enums\Module;
use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Enums\TariffProduct;
use App\Models\DailyReportTask;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Models\Settlement;
use App\Models\User;
use App\Settlements\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A C2B partner is a staff member restricted to one product: everything
 * behind the partner modules is narrowed to C2B, and no card terms, provider
 * costs or profit reach them.
 */
class C2bPartnerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $partner;

    private Provider $c2bBank;

    private Provider $cardBank;

    private Merchant $mixed;

    private Merchant $cardOnly;

    private Merchant $c2bOnly;

    private MerchantMid $cardMid;

    private MerchantMid $c2bMid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->superAdmin()->create();
        $this->partner = User::factory()->withModules(Module::forPartners())->create(['product_scope' => TariffProduct::C2b->value]);

        $this->c2bBank = Provider::factory()->create(['name' => 'FinBridges', 'code' => 'finbridges', 'type' => ProviderType::Bank, 'product' => TariffProduct::C2b]);
        $this->cardBank = Provider::factory()->create(['name' => 'Cardaq', 'code' => 'cardaq', 'type' => ProviderType::Bank]);

        $this->c2bOnly = Merchant::factory()->create(['name' => 'Alpha Transfers']);
        $this->mixed = Merchant::factory()->create(['name' => 'Mixed Shop']);
        $this->cardOnly = Merchant::factory()->create(['name' => 'Zed Cards']);

        $tariff = ['product' => 'c2b', 'transfer_percent' => 1.5, 'success_fixed' => 0, 'refund_fixed' => 0, 'fiat_to_crypto_percent' => 0, 'settlement_fx_percent' => 0];
        $this->c2bOnly->tariffs()->create($tariff);
        $this->mixed->tariffs()->create($tariff);

        $this->c2bMid = MerchantMid::factory()->for($this->mixed)->create(['mid' => 'C2B-MIXED', 'bank_provider_id' => $this->c2bBank->id]);
        MerchantMid::factory()->for($this->c2bOnly)->create(['mid' => 'C2B-ALPHA', 'bank_provider_id' => $this->c2bBank->id]);
        $this->cardMid = MerchantMid::factory()->for($this->mixed)->create(['mid' => 'CARD-MIXED', 'bank_provider_id' => $this->cardBank->id]);
        MerchantMid::factory()->for($this->cardOnly)->create(['mid' => 'CARD-ZED', 'bank_provider_id' => $this->cardBank->id]);
    }

    private function operation(MerchantMid $mid, Provider $provider, string $payment): MerchantOperation
    {
        return MerchantOperation::query()->create([
            'merchant_id' => $mid->merchant_id, 'merchant_mid_id' => $mid->id, 'provider_id' => $provider->id,
            'role' => $provider->type, 'mid' => $mid->mid, 'payment_id' => $payment, 'operation_type' => OperationType::Sale,
            'trn_type' => 'incoming', 'ips' => 'transfer', 'resolution' => 'completed', 'reference' => $payment,
            'amount' => '100.00', 'currency' => 'EUR', 'transaction_at' => '2026-10-19 10:00:00', 'report_date' => '2026-10-19',
        ]);
    }

    private function report(MerchantMid $mid, string $payout): DailyReportTask
    {
        return DailyReportTask::query()->create([
            'merchant_id' => $mid->merchant_id, 'merchant_mid_id' => $mid->id, 'report_date' => '2026-10-19',
            'period_from' => '2026-10-19', 'period_to' => '2026-10-19', 'currency' => 'EUR', 'status' => ReportStatus::Completed,
            'turnover' => $payout, 'net_payout' => $payout, 'total_provider_cost' => 3, 'net_profit' => 7,
        ]);
    }

    public function test_the_partner_sees_only_c2b_merchants_and_no_card_terms()
    {
        $this->actingAs($this->partner)->get(route('admin.merchants.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('merchants.data', 2)
                ->where('merchants.data.0.name', 'Alpha Transfers')
                ->where('merchants.data.1.name', 'Mixed Shop')
                ->missing('merchants.data.1.fee_visa_eu_percent'));

        $this->get(route('admin.merchants.show', $this->cardOnly))->assertNotFound();

        $this->get(route('admin.merchants.show', $this->mixed))
            ->assertInertia(fn (Assert $page) => $page
                ->has('merchant.mids', 1)
                ->where('merchant.mids.0.mid', 'C2B-MIXED')
                ->missing('merchant.fee_visa_eu_percent')
                ->has('bankProviders', 1));

        // Unrestricted staff still see everything.
        $this->actingAs($this->admin)->get(route('admin.merchants.index'))
            ->assertInertia(fn (Assert $page) => $page->has('merchants.data', 3));
    }

    public function test_the_partner_gets_the_c2b_overview_and_nothing_beyond_the_partner_modules()
    {
        $this->actingAs($this->partner)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/c2b/overview')->where('product.value', 'c2b'));

        $this->get(route('admin.profit.index'))->assertForbidden();
        $this->get(route('admin.providers.index'))->assertForbidden();
        $this->get(route('admin.team.index'))->assertForbidden();

        $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->component('admin/dashboard'));
    }

    public function test_the_partner_cannot_delete_a_merchant_or_reach_card_operations()
    {
        $card = $this->operation($this->cardMid, $this->cardBank, 'CARD-OP');
        $transfer = $this->operation($this->c2bMid, $this->c2bBank, 'C2B-OP');

        $this->actingAs($this->partner)->get(route('admin.operations.index'))
            ->assertInertia(fn (Assert $page) => $page->has('operations.data', 1));
        $this->get(route('admin.operations.show', $card))->assertNotFound();

        // The provider's cost is ours: the partner sees what the merchant pays only.
        $this->get(route('admin.operations.show', $transfer))
            ->assertInertia(fn (Assert $page) => $page
                ->where('charges.merchant_percent', '1.5')
                ->where('charges.provider_cost', null)
                ->where('fees', []));

        $this->delete(route('admin.merchants.destroy', $this->mixed), ['confirm' => 'Mixed Shop'])->assertForbidden();
    }

    public function test_settlements_pay_one_product_and_the_partner_only_makes_c2b_ones()
    {
        $this->report($this->cardMid, '100.00');
        $this->report($this->c2bMid, '50.00');
        $service = app(SettlementService::class);

        $card = $service->createDraft($this->mixed, $this->admin);
        $c2b = $service->createDraft($this->mixed, $this->admin, product: TariffProduct::C2b);

        $this->assertSame(TariffProduct::Card, $card->product);
        $this->assertSame(['100.0000'], $card->lines()->pluck('amount')->all());
        $this->assertSame(TariffProduct::C2b, $c2b->product);
        $this->assertSame(['50.0000'], $c2b->lines()->pluck('amount')->all());

        $this->actingAs($this->partner)->get(route('admin.settlements.index'))
            ->assertInertia(fn (Assert $page) => $page->has('settlements.data', 1)->where('settlements.data.0.number', $c2b->number));
        $this->get(route('admin.settlements.show', $card))->assertNotFound();

        // Asking for the card product changes nothing: a partner's settlement is C2B.
        $this->post(route('admin.settlements.store'), ['merchant' => $this->mixed->public_id, 'product' => 'card'])->assertRedirect();
        $this->assertSame(
            [TariffProduct::C2b->value],
            Settlement::withoutGlobalScopes()->where('id', '>', $c2b->id)->pluck('product')->map(fn ($p) => $p->value)->unique()->values()->all(),
        );
    }

    public function test_the_partner_creates_merchants_with_a_c2b_tariff_only()
    {
        $this->actingAs($this->partner)->post(route('admin.merchants.store'), [
            'name' => 'Fresh Transfers',
            'status' => 'onboarding',
            'tariffs' => ['c2b' => ['transfer_percent' => '1.2']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $merchant = Merchant::withoutGlobalScopes()->where('name', 'Fresh Transfers')->sole();
        $this->assertSame('1.200', $merchant->tariffs()->withoutGlobalScopes()->where('product', 'c2b')->sole()->transfer_percent);

        $this->get(route('admin.merchants.index'))
            ->assertInertia(fn (Assert $page) => $page->has('merchants.data', 3));
    }

    public function test_a_partner_user_is_made_in_the_team_screen_with_partner_modules_only()
    {
        $base = ['name' => 'Partner', 'email' => 'partner@example.com', 'role' => 'admin', 'product_scope' => 'c2b'];

        $this->actingAs($this->admin)->post(route('admin.team.store'), [...$base, 'permissions' => ['merchants', 'profit']])
            ->assertSessionHasErrors('permissions');

        $this->post(route('admin.team.store'), [...$base, 'permissions' => ['merchants', 'settlements']])->assertSessionHasNoErrors();

        $created = User::query()->where('email', 'partner@example.com')->sole();
        $this->assertSame(TariffProduct::C2b, $created->productScope());
        $this->assertSame(['merchants', 'settlements'], $created->permissions);
    }
}

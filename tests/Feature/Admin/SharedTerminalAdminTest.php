<?php

namespace Tests\Feature\Admin;

use App\Enums\Currency;
use App\Enums\MerchantStatus;
use App\Enums\ProviderType;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedTerminalAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_terminal_is_shared_with_another_merchant_from_the_mid_form()
    {
        $admin = User::factory()->superAdmin()->create();
        $bank = Provider::factory()->create(['name' => 'Cardaq', 'code' => 'cardaq', 'type' => ProviderType::Bank]);
        $gate = Provider::factory()->gate()->create(['name' => 'Corefy', 'code' => 'corefy']);
        $first = Merchant::factory()->create(['name' => 'Client A', 'status' => MerchantStatus::Active]);
        $second = Merchant::factory()->create(['name' => 'Client B', 'status' => MerchantStatus::Active]);
        $third = Merchant::factory()->create(['name' => 'Client C', 'status' => MerchantStatus::Active]);
        $primary = MerchantMid::factory()->for($first)->create([
            'mid' => '6741425', 'currency' => Currency::Eur, 'bank_provider_id' => $bank->id, 'gate_provider_id' => $gate->id, 'gate_mid' => 'coma_A',
        ]);
        $payload = ['bank_mid' => '6741425', 'currency' => 'EUR', 'status' => 'active', 'bank_provider_id' => $bank->id, 'gate_provider_id' => $gate->id, 'gate_mid' => 'coma_B', 'rolling_reserve_limit' => 100000];

        // The key of the record is made from the terminal number; the bank's number is kept apart.
        $this->actingAs($admin)->post(route('admin.merchants.mids.store', $second), $payload)->assertSessionHasNoErrors();
        $b = MerchantMid::query()->where('merchant_id', $second->id)->sole();
        $this->assertSame(['6741425-B', '6741425', '6741425'], [$b->mid, $b->bank_mid, $b->terminalNumber()]);
        $this->post(route('admin.merchants.mids.store', $third), [...$payload, 'gate_mid' => 'coma_C'])->assertSessionHasNoErrors();
        $this->assertSame('6741425-C', MerchantMid::query()->where('merchant_id', $third->id)->value('mid'));

        // The terminal, and a gateway account of its own, and its currency are needed.
        $this->post(route('admin.merchants.mids.store', $third), [...$payload, 'bank_mid' => 'NOPE', 'gate_mid' => 'coma_X'])->assertSessionHasErrors('bank_mid');
        $this->post(route('admin.merchants.mids.store', $third), [...$payload, 'gate_mid' => ''])->assertSessionHasErrors('gate_mid');
        $this->post(route('admin.merchants.mids.store', $third), [...$payload, 'gate_mid' => 'coma_Y', 'currency' => 'USD'])->assertSessionHasErrors('currency');
        // A merchant does not share a terminal with itself.
        $this->post(route('admin.merchants.mids.store', $first), [...$payload, 'gate_mid' => 'coma_Z'])->assertSessionHasErrors('bank_mid');

        // The merchant page tells who a terminal is shared with.
        $props = $this->get(route('admin.merchants.show', $first))->viewData('page')['props'];
        $this->assertEqualsCanonicalizing(['Client B', 'Client C'], $props['merchant']['mids'][0]['shared_with']);
        $this->assertSame('6741425', $props['merchant']['mids'][0]['terminal']);
        $this->assertSame($primary->id, $props['merchant']['mids'][0]['id']);
    }
}

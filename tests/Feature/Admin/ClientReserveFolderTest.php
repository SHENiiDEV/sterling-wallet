<?php

namespace Tests\Feature\Admin;

use App\Enums\ReserveEntryType;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Company;
use App\Models\Merchant;
use App\Models\ReserveLedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientReserveFolderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_client_folder_shows_the_reserve_held_over_all_its_companies()
    {
        $client = Client::query()->create(['name' => 'DNS', 'reserve_pool_enabled' => true, 'reserve_pool_limit' => 100000]);
        $a = Merchant::factory()->create(['company_id' => Company::query()->create(['name' => 'A', 'client_id' => $client->id])->id]);
        $b = Merchant::factory()->create(['company_id' => Company::query()->create(['name' => 'B', 'client_id' => $client->id])->id]);
        foreach ([[$a, '300.00'], [$a, '200.00'], [$b, '50.50']] as [$m, $amount]) {
            ReserveLedgerEntry::query()->create(['merchant_id' => $m->id, 'currency' => 'EUR', 'type' => ReserveEntryType::Hold, 'amount' => $amount]);
        }

        $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->actingAs($admin)->get(route('admin.merchants.folders', ['client' => $client->id]))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('open.reserve.pool', true)
                ->where('open.reserve.totals.0.currency', 'EUR')
                ->where('open.reserve.totals.0.amount', '550.50')
                ->has('open.reserve.by_merchant', 2));
    }
}

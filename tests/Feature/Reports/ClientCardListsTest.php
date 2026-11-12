<?php

namespace Tests\Feature\Reports;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\Client;
use App\Models\Company;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\User;
use App\Reports\CardTraffic;
use App\Reports\Generation\ClientCardLists;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class ClientCardListsTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private Client $client;

    private MerchantMid $mid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::query()->create(['name' => 'Luneholm group', 'invoice_email' => 'finance@luneholm.test']);
        $company = Company::factory()->create(['name' => 'LUNEHOLM LTD', 'client_id' => $this->client->id]);
        $this->mid = $this->mid($this->merchantWithTariff(['company_id' => $company->id]), $this->cardaq(), $this->corefy(), ['mid' => '4400000001', 'gate_mid' => 'coma_TEST1']);
    }

    private function attempt(string $last4, OperationType $type, ?string $resolution = null, string $day = '2026-11-03', string $bin = '411111'): void
    {
        MerchantOperation::query()->create([
            'merchant_id' => $this->mid->merchant_id, 'merchant_mid_id' => $this->mid->id, 'provider_id' => $this->mid->gate_provider_id, 'role' => ProviderType::Gate,
            'operation_type' => $type, 'resolution' => $resolution, 'amount' => '10.00', 'currency' => 'EUR', 'card_bin' => $bin, 'card_last4' => $last4,
            'report_date' => $day, 'transaction_at' => CarbonImmutable::parse("{$day} 10:00:00", 'UTC'),
        ]);
    }

    public function test_cards_are_listed_as_masks_in_white_tiers_and_a_black_list()
    {
        // 0001 paid 3 times, 0002 once and was declined once, 0003 never paid and ran out of funds three times,
        // 0004 never paid and was declined for other reasons four times, 0005 only had a routing error.
        foreach ([1, 2, 3] as $_) {
            $this->attempt('0001', OperationType::Sale);
        }
        $this->attempt('0002', OperationType::Sale);
        $this->attempt('0002', OperationType::Decline, 'do_not_honor');
        foreach ([1, 2, 3] as $_) {
            $this->attempt('0003', OperationType::Decline, 'insufficient_funds');
        }
        foreach (['do_not_honor', 'do_not_honor', 'fraud', '3ds_failed'] as $reason) {
            $this->attempt('0004', OperationType::Decline, $reason);
        }
        $this->attempt('0005', OperationType::Decline, 'ROUTING_ERROR');

        $data = app(ClientCardLists::class)->data($this->client, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-07'));
        $masks = fn (string $list) => array_column($data['lists'][$list], 'mask');

        $this->assertCount(4, $data['cards']);
        $this->assertSame(['411111*0001', '411111*0002'], $masks('white1'));
        $this->assertSame(['411111*0001'], $masks('white3'));
        $this->assertSame([], $masks('white5'));
        $this->assertEqualsCanonicalizing(['411111*0003', '411111*0004'], $masks('black'));
        $this->assertSame(['411111*0003'], $masks('insufficient'));
        $this->assertSame([2, 1, 50.0, 'do not honor'], [$data['cards'][1]['attempts'], $data['cards'][1]['successes'], $data['cards'][1]['approval'], $data['cards'][1]['top_reason']]);

        $csv = app(ClientCardLists::class)->csv($data, 'white3');
        $this->assertStringContainsString('411111*0001', $csv);
        $this->assertStringNotContainsString('411111*0002', $csv);

        $admin = User::factory()->create();
        $this->actingAs($admin)->get(route('admin.clients.cards', ['client' => $this->client, 'date' => '2026-11-01', 'to' => '2026-11-07']))
            ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response = $this->actingAs($admin)->get(route('admin.clients.cards', ['client' => $this->client, 'date' => '2026-11-01', 'to' => '2026-11-07', 'format' => 'csv', 'list' => 'black']));
        $response->assertOk();
        $this->assertStringContainsString('411111*0003', $response->getContent());
        $this->actingAs($admin)->get(route('admin.clients.cards', ['client' => $this->client, 'date' => '2026-11-01', 'to' => '2026-11-07', 'format' => 'csv', 'list' => 'nope']))->assertSessionHasErrors('list');
    }

    public function test_the_card_traffic_can_be_grouped_by_client_with_its_companies_and_mids()
    {
        config(['sterling.traffic.warmup_days' => 0]);
        $this->attempt('0001', OperationType::Sale, day: '2026-11-03');
        $this->attempt('0002', OperationType::Sale, day: '2026-11-03');

        $from = CarbonImmutable::parse('2026-11-01');
        $to = CarbonImmutable::parse('2026-11-07');
        $owners = app(CardTraffic::class)->owners($from, $to, CardTraffic::BY_CLIENT);

        $this->assertCount(1, $owners);
        $this->assertSame([$this->client->id, 'Luneholm group', 2, 2], [$owners[0]['client_id'], $owners[0]['merchant']['name'], $owners[0]['sales'], $owners[0]['cards']]);

        $this->travelTo('2026-11-10');
        $this->actingAs(User::factory()->create())->get(route('admin.traffic.index', ['by' => 'client', 'client' => $this->client->id, 'days' => 90]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('by', 'client')
            ->has('owners', 1)
            ->where('selected.name', 'Luneholm group')
            ->has('selected.members', 1)
            ->where('selected.members.0.mid', '4400000001')
            ->where('selected.members.0.company', 'LUNEHOLM LTD'));
    }
}

<?php

namespace Tests\Feature\Reports;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\User;
use App\Reports\CardTraffic;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class CardTrafficTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private MerchantMid $mid;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sterling.traffic.warmup_days' => 2, 'sterling.traffic.new_card_share_limit' => 20]);
        $this->mid = $this->mid($this->merchantWithTariff(), $this->cardaq(), $this->corefy());
    }

    private function sale(string $day, string $last4, string $role = 'gate', string $bin = '411111'): void
    {
        $role = ProviderType::from($role);
        MerchantOperation::query()->create([
            'merchant_id' => $this->mid->merchant_id,
            'merchant_mid_id' => $this->mid->id,
            'provider_id' => $role === ProviderType::Bank ? $this->mid->bank_provider_id : $this->mid->gate_provider_id,
            'role' => $role,
            'operation_type' => OperationType::Sale,
            'amount' => '10.00',
            'currency' => 'EUR',
            'card_bin' => $bin,
            'card_last4' => $last4,
            'report_date' => $day,
            'transaction_at' => CarbonImmutable::parse("{$day} 12:00", 'UTC'),
        ]);
    }

    public function test_it_counts_cards_first_seen_on_the_day_and_flags_a_high_share_after_the_warm_up()
    {
        // Days 1–2 build the history (every card is new); from day 3 the traffic is judged.
        foreach (['0001', '0002', '0003', '0004', '0005'] as $card) {
            $this->sale('2026-10-01', $card);
        }
        $this->sale('2026-10-02', '0001');
        $this->sale('2026-10-02', '0002');
        // Day 3: five cards, one new (20%: not above the limit).
        foreach (['0001', '0002', '0003', '0004', '9001'] as $card) {
            $this->sale('2026-10-03', $card);
        }
        // Day 4: four cards, three new (75%: high). A repeated card on the same day counts once.
        foreach (['0001', '9002', '9003', '9004', '9004'] as $card) {
            $this->sale('2026-10-04', $card);
        }
        // The acquirer's copy of a gateway sale is not counted twice.
        $this->sale('2026-10-04', '0001', 'bank');

        $days = app(CardTraffic::class)->daily(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-04'))->keyBy('day');

        $this->assertSame([5, 5, 100.0, true, false], [$days['2026-10-01']['cards'], $days['2026-10-01']['new_cards'], $days['2026-10-01']['new_share'], $days['2026-10-01']['warming'], $days['2026-10-01']['high']]);
        $this->assertSame([2, 0, false], [$days['2026-10-02']['cards'], $days['2026-10-02']['new_cards'], $days['2026-10-02']['high']]);
        $this->assertSame([5, 1, 20.0, false, false], [$days['2026-10-03']['cards'], $days['2026-10-03']['new_cards'], $days['2026-10-03']['new_share'], $days['2026-10-03']['warming'], $days['2026-10-03']['high']]);
        $this->assertSame([4, 3, 75.0, true], [$days['2026-10-04']['cards'], $days['2026-10-04']['new_cards'], $days['2026-10-04']['new_share'], $days['2026-10-04']['high']]);
        $this->assertSame(5, $days['2026-10-04']['sales']); // the bank row is left out

        $merchants = app(CardTraffic::class)->merchants(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-04'));
        // Judged days 3 and 4: 4 new of 9 cards.
        $this->assertSame([44.4, 'high', 1], [$merchants[0]['new_share'], $merchants[0]['status'], $merchants[0]['high_days']]);
    }

    public function test_the_page_lists_merchants_and_a_merchant_by_day()
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00', config('sterling.timezone')));
        $this->sale('2026-10-09', '0001');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->get(route('admin.traffic.index', ['days' => 14]))
            ->assertInertia(fn (Assert $page) => $page->component('admin/traffic/index')->where('limit', 20)->has('merchants', 1)->where('selected', null));

        $this->get(route('admin.traffic.index', ['merchant' => $this->mid->merchant->public_id]))
            ->assertInertia(fn (Assert $page) => $page->where('selected.name', $this->mid->merchant->name)->has('selected.days', 1));
    }
}

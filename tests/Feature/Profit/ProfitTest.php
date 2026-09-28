<?php

namespace Tests\Feature\Profit;

use App\Enums\Currency;
use App\Enums\Module;
use App\Enums\StatementStatus;
use App\Models\CommercialOffer;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\MonthlyStatement;
use App\Models\ProfitPartner;
use App\Models\User;
use App\Profit\ProfitQuery;
use App\Profit\ProfitShareCalculator;
use App\Reports\Ingestion\ReportIngestionService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

/**
 * Two MIDs on two provider pairs, each with the worked-example report
 * (net profit 6.91, turnover 350 in the MID currency):
 *  - EUR MID on Cardaq ↔ Corefy;
 *  - USD MID on Madfin ↔ Corefy, converted to EUR at 1/1.10.
 */
class ProfitTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private User $admin;

    private Merchant $eurMerchant;

    private Merchant $usdMerchant;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Europe/Riga'));
        $this->admin = User::factory()->superAdmin()->create();
        $this->eurUsdRate('2026-09-01', '1.10');

        $oxen = $this->oxen();
        $corefy = $this->corefy();
        $this->eurMerchant = $this->merchantWithTariff(['name' => 'Euro Shop', 'crypto_provider_id' => $oxen->id]);
        $this->usdMerchant = $this->merchantWithTariff(['name' => 'Dollar Shop', 'crypto_provider_id' => $oxen->id]);

        $eurMid = $this->mid($this->eurMerchant, $this->cardaq(), $corefy);
        $usdMid = $this->mid($this->usdMerchant, $this->madfin(), $corefy, ['mid' => '5500000001', 'gate_mid' => 'coma_USD', 'currency' => Currency::Usd]);

        $this->completeReport($eurMid, $this->cardaqCsv(day: '2026-09-15'), '2026-09-15');
        $this->completeReport($usdMid, $this->csvFile([
            ['Merchant', 'Terminal ID', 'Transaction ID', 'PAN', 'Scheme', 'Region', 'Transaction type', 'Amount', 'Currency', 'Transaction date'],
            ['SHOP', '5500000001', 'U1', '411111XXXXXX1111', 'Visa', 'EU', 'Sale', '100,00', 'USD', '15.09.2026 10:00'],
            ['SHOP', '5500000001', 'U2', '555555XXXXXX4444', 'Mastercard', 'Non-EU', 'Sale', '200,00', 'USD', '15.09.2026 11:00'],
            ['SHOP', '5500000001', 'U3', '422222XXXXXX2222', 'Visa', 'Non-EU', 'Sale', '50,00', 'USD', '15.09.2026 12:00'],
            ['SHOP', '5500000001', 'U4', '411111XXXXXX1111', 'Visa', 'EU', 'Refund', '30,00', 'USD', '15.09.2026 13:00'],
        ], 'madfin.csv', ';'), '2026-09-15');
    }

    private function completeReport(MerchantMid $mid, $bankFile, string $day): void
    {
        $ingestion = app(ReportIngestionService::class);
        $ingestion->ingest($mid->bankProvider, $bankFile, CarbonImmutable::parse($day));
        $ingestion->ingest($mid->gateProvider, $this->corefyCsv($mid->gate_mid, $mid->currency->value, $day), CarbonImmutable::parse($day));
    }

    public function test_summary_and_pairs_are_in_eur_at_frozen_rates()
    {
        $q = app(ProfitQuery::class);
        $from = CarbonImmutable::parse('2026-09-01');
        $to = CarbonImmutable::parse('2026-09-30');

        $summary = $q->summary($from, $to);
        // 350 + 350 / 1.1 = 668.18; 6.91 + 6.91 / 1.1 = 13.19
        $this->assertSame(668.18, $summary['turnover']);
        $this->assertSame(13.19, $summary['net_profit']);
        $this->assertSame(2, $summary['reports']);
        $this->assertSame(6, $summary['sales']);
        $this->assertEqualsWithDelta(15.9 + 15.9 / 1.1, $summary['revenue'], 0.02); // 14.80 + 1.10 per report

        $pairs = collect($q->byProviderPair($from, $to))->keyBy('pair');
        $this->assertSame(['Cardaq ↔ Corefy', 'Madfin ↔ Corefy'], $pairs->keys()->sort()->values()->all());
        $this->assertSame(6.91, $pairs['Cardaq ↔ Corefy']['net_profit']);
        $this->assertSame(6.28, $pairs['Madfin ↔ Corefy']['net_profit']);

        $this->assertCount(30, $q->daily($from, $to));
        $this->assertSame(13.19, collect($q->daily($from, $to))->firstWhere('date', '2026-09-15')['net_profit']);

        $breakdowns = $q->breakdowns($from, $to);
        // Each pair's gateway file: 3 approved, 2 declined → 60 %.
        $this->assertSame([60.0, 60.0], array_column($breakdowns['conversion'], 'rate'));
        $this->assertSame(6, array_sum(array_column($breakdowns['schemes'], 'operations')));
        $this->assertSame(['do_not_honor', 'insufficient_funds'], collect($breakdowns['decline_reasons'])->pluck('reason')->unique()->sort()->values()->all());
    }

    public function test_dashboard_shows_profit_only_with_the_profit_module()
    {
        $this->actingAs($this->admin)->get(route('admin.dashboard', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('profit.summary.net_profit', 13.19)
                ->where('profit.previous.net_profit', 0)
                ->has('profit.byPair', 2)
                ->has('operations.reserves', 2));

        $reportsOnly = User::factory()->withModules([Module::Reports])->create();
        $this->actingAs($reportsOnly)->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('profit', null)->has('operations'));

        $this->actingAs($this->admin)->get(route('admin.profit.index', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/profit/index')->has('breakdowns.schemes'));
    }

    public function test_profit_share_default_rule_merchant_override_and_mid_month_start()
    {
        $anna = ProfitPartner::query()->create(['name' => 'Anna']);
        $bob = ProfitPartner::query()->create(['name' => 'Bob']);

        // Anna: 20% of net profit everywhere, but 50% on Euro Shop.
        $anna->rules()->create(['base' => 'net_profit', 'percent' => 20, 'valid_from' => '2026-01-01']);
        $anna->rules()->create(['merchant_id' => $this->eurMerchant->id, 'base' => 'net_profit', 'percent' => 50, 'valid_from' => '2026-01-01']);
        // Bob: 1% of turnover, only from 20 Sep (after this month's only report day).
        $bob->rules()->create(['base' => 'turnover', 'percent' => 1, 'valid_from' => '2026-09-20']);

        $statement = app(ProfitShareCalculator::class)->calculate('2026-09');

        $this->assertMoney('13.19', $statement->net_profit);
        $lines = $statement->lines->keyBy(fn ($l) => $l->partner_name.'|'.$l->merchant_name);
        $this->assertCount(2, $lines); // Bob's rule starts after the report day
        $this->assertMoney('3.46', $lines['Anna|Euro Shop']->share);   // 50% × 6.91
        $this->assertMoney('1.26', $lines['Anna|Dollar Shop']->share); // 20% × 6.28
        $this->assertMoney('8.47', $statement->company_remainder);
    }

    public function test_closed_month_is_frozen_and_negative_remainder_is_shown()
    {
        $partner = ProfitPartner::query()->create(['name' => 'Greedy']);
        $partner->rules()->create(['base' => 'turnover', 'percent' => 5, 'valid_from' => '2026-09-01']);

        $this->actingAs($this->admin)->get(route('admin.profit-share.index', ['month' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('statement.status', 'draft')
                ->where('statement.shares_total', '33.4100') // 5% × 668.18
                ->where('statement.company_remainder', '-20.2200'));

        $this->actingAs($this->admin)->post(route('admin.profit-share.close', '2026-09'))->assertRedirect();
        $statement = MonthlyStatement::query()->where('month', '2026-09')->sole();
        $this->assertSame(StatementStatus::Closed, $statement->status);

        // A new rule reaching into the closed month is refused; results stay frozen.
        $this->actingAs($this->admin)->post(route('admin.profit-share.rules.store', $partner), [
            'base' => 'net_profit', 'percent' => 10, 'valid_from' => '2026-09-01',
        ])->assertSessionHasErrors('valid_from');
        $partner->rules()->update(['percent' => 50]);
        $this->assertMoney('33.41', app(ProfitShareCalculator::class)->calculate('2026-09')->shares_total);

        $this->actingAs($this->admin)->get(route('admin.profit-share.pdf', '2026-09'))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Current month can't be closed yet.
        MonthlyStatement::query()->create(['month' => '2026-10', 'status' => 'draft']);
        $this->actingAs($this->admin)->post(route('admin.profit-share.close', '2026-10'))->assertSessionHasErrors('statement');
    }

    public function test_offer_lifecycle_ends_in_a_merchant_with_the_offered_tariff()
    {
        $payload = [
            'company_name' => 'Padel Club Ltd', 'contact_email' => 'cfo@padel.test', 'country' => 'lv', 'currencies' => ['EUR', 'GBP'],
            'fee_visa_eu_percent' => 2.9, 'fee_visa_non_eu_percent' => 3.9, 'fee_mastercard_eu_percent' => 2.9, 'fee_mastercard_non_eu_percent' => 3.9,
            'fee_acq_eu_percent' => 2.9, 'fee_acq_non_eu_percent' => 3.9,
            'fee_success_fixed' => 0.25, 'fee_decline_fixed' => 0.1, 'fee_refund_fixed' => 1, 'fee_chargeback_fixed' => 25,
            'fee_fiat_to_crypto_percent' => 0.5, 'setup_fee' => 500, 'rolling_reserve_percent' => 5, 'rolling_reserve_days' => 90,
            'valid_until' => '2026-12-31', 'terms' => 'Net 7 days.',
        ];

        $this->actingAs($this->admin)->post(route('admin.offers.store'), $payload)->assertRedirect();
        $offer = CommercialOffer::query()->sole();
        $this->assertMatchesRegularExpression('/^OFF-\d{4}-\d{4}$/', $offer->number);
        $this->assertSame('LV', $offer->country);

        $this->actingAs($this->admin)->get(route('admin.offers.pdf', $offer))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->admin)->post(route('admin.offers.status', $offer), ['status' => 'sent'])->assertRedirect();
        $this->assertSame('sent', $offer->fresh()->status->value);

        $this->actingAs($this->admin)->post(route('admin.offers.accept', $offer))->assertRedirect();
        $offer->refresh();
        $merchant = $offer->merchant;

        $this->assertSame('accepted', $offer->status->value);
        $this->assertSame('Padel Club Ltd', $merchant->name);
        $this->assertSame('onboarding', $merchant->status->value);
        $this->assertSame('2.900', $merchant->fee_visa_eu_percent);
        $this->assertSame('0.2500', $merchant->fee_success_fixed);
        $this->assertSame(90, $merchant->rolling_reserve_days);
        $this->assertSame('cfo@padel.test', $merchant->invoice_email);

        $this->actingAs($this->admin)->put(route('admin.offers.update', $offer), $payload)->assertSessionHasErrors('offer');
        $this->actingAs($this->admin)->delete(route('admin.offers.destroy', $offer))->assertSessionHasErrors('offer');
    }

    private function assertMoney(string $expected, mixed $actual): void
    {
        $this->assertTrue(BigDecimal::of((string) $actual)->isEqualTo($expected), "Expected {$expected}, got {$actual}.");
    }
}

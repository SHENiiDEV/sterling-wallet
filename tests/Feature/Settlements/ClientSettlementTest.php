<?php

namespace Tests\Feature\Settlements;

use App\Enums\SettlementLineType;
use App\Models\Client;
use App\Models\Company;
use App\Models\DailyReportTask;
use App\Models\FxRate;
use App\Models\Merchant;
use App\Models\Provider;
use App\Models\Settlement;
use App\Models\User;
use App\Reports\Ingestion\ReportIngestionService;
use App\Settlements\SettlementService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class ClientSettlementTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private User $admin;

    private Client $client;

    private Provider $bank;

    private Provider $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->superAdmin()->create();
        $this->client = Client::query()->create(['name' => 'Luneholm group']);
        $this->bank = $this->cardaq();
        $this->gate = $this->corefy();
        FxRate::query()->create(['rate_date' => '2026-09-01', 'base' => 'EUR', 'quote' => 'USD', 'rate' => '1.10']);
    }

    /**
     * A merchant of a company with a MID and one completed report for the day.
     */
    private function merchantWithReport(?Client $client, string $name, string $mid, string $account): Merchant
    {
        $company = Company::factory()->create(['name' => $name, 'client_id' => $client?->id]);
        $merchant = $this->merchantWithTariff(['company_id' => $company->id, 'name' => $name]);
        $this->mid($merchant, $this->bank, $this->gate, ['mid' => $mid, 'gate_mid' => $account]);

        $ingestion = app(ReportIngestionService::class);
        $day = CarbonImmutable::parse('2026-09-15');
        $ingestion->ingest($this->bank, $this->cardaqCsv($mid), $day);
        $ingestion->ingest($this->gate, $this->corefyCsv($account), $day);

        return $merchant;
    }

    private function assertMoney(string $expected, mixed $actual): void
    {
        $this->assertTrue(BigDecimal::of((string) $actual)->isEqualTo($expected), "Expected {$expected}, got {$actual}.");
    }

    public function test_a_client_draft_pays_every_company_in_usdt_through_the_usd_peg()
    {
        $first = $this->merchantWithReport($this->client, 'Company A', '4400000001', 'coma_A');
        $this->merchantWithReport($this->client, 'Company B', '4400000002', 'coma_B');
        $this->merchantWithReport(null, 'Someone else', '4400000003', 'coma_C');
        $wallet = $first->wallets()->create(['type' => 'provider_inflow', 'currency' => 'USDT', 'network' => 'TRC20', 'address' => 'TXabc']);

        $settlement = app(SettlementService::class)->createClientDraft($this->client, $this->admin, 'USDT');

        $this->assertSame($this->client->id, $settlement->client_id);
        $this->assertSame('USDT', $settlement->payout_currency);
        $this->assertSame($wallet->id, $settlement->wallet_id);
        $this->assertSame(2, $settlement->lines()->where('type', SettlementLineType::Report)->count());
        // 273.58 EUR × 1.10 per report, rounded per line.
        $this->assertSame('1.1', $settlement->rates['EUR']);
        $this->assertMoney('601.88', $settlement->total_payout);

        // Reports of other clients stay out, even when added by hand.
        $foreign = DailyReportTask::query()->whereHas('merchant', fn ($q) => $q->where('name', 'Someone else'))->sole();
        app(SettlementService::class)->addReports($settlement, [$foreign]);
        $this->assertSame(2, $settlement->lines()->where('type', SettlementLineType::Report)->count());
    }

    public function test_changing_the_payout_currency_resets_the_rates()
    {
        $this->merchantWithReport($this->client, 'Company A', '4400000001', 'coma_A');
        $service = app(SettlementService::class);
        $settlement = $service->createClientDraft($this->client, $this->admin, 'USDT');
        $service->setRates($settlement, ['EUR' => '1.50']);

        $settlement = $service->setPayoutCurrency($settlement->fresh(), 'USDC');

        $this->assertSame('USDC', $settlement->payout_currency);
        $this->assertSame('1.1', $settlement->rates['EUR']);
    }

    public function test_client_settlement_screens_and_actions()
    {
        $this->merchantWithReport($this->client, 'Company A', '4400000001', 'coma_A');
        $this->merchantWithReport($this->client, 'Company B', '4400000002', 'coma_B');

        $this->actingAs($this->admin)->get(route('admin.settlements.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingClients.0.name', 'Luneholm group')
                ->where('pendingClients.0.companies', 2)
                ->where('pendingClients.0.reports', 2));

        $this->post(route('admin.settlements.store'), ['client' => $this->client->id, 'payout_currency' => 'USDT'])->assertRedirect();
        $settlement = Settlement::query()->sole();

        $this->get(route('admin.settlements.show', $settlement))
            ->assertInertia(fn (Assert $page) => $page
                ->where('settlement.client.name', 'Luneholm group')
                ->where('settlement.payout_currency', 'USDT')
                ->has('lines', 2)
                ->has('payoutCurrencies'));

        $this->put(route('admin.settlements.update', $settlement), ['payout_currency' => 'USDC'])->assertSessionHasNoErrors();
        $this->assertSame('USDC', $settlement->fresh()->payout_currency);

        $this->get(route('admin.settlements.pdf', $settlement))->assertOk();
    }
}

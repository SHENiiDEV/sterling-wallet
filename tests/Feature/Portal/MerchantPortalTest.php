<?php

namespace Tests\Feature\Portal;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\DailyReportTask;
use App\Models\FxRate;
use App\Models\Merchant;
use App\Models\User;
use App\Reports\Ingestion\ReportIngestionService;
use App\Settlements\SettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class MerchantPortalTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private User $admin;

    private Merchant $ours;

    private Merchant $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00', 'Europe/Riga'));
        $this->admin = User::factory()->superAdmin()->create();
        FxRate::query()->create(['rate_date' => '2026-09-01', 'base' => 'EUR', 'quote' => 'USDC', 'rate' => '1.10']);

        $cardaq = $this->cardaq();
        $corefy = $this->corefy();
        $this->ours = $this->merchantWithTariff(['name' => 'Our Shop', 'company_id' => Company::factory()->create(['name' => 'Our Co'])->id]);
        $this->theirs = $this->merchantWithTariff(['name' => 'Their Shop', 'company_id' => Company::factory()->create(['name' => 'Their Co'])->id]);
        $this->mid($this->ours, $cardaq, $corefy);
        $this->mid($this->theirs, $cardaq, $corefy, ['mid' => '4400000002', 'gate_mid' => 'coma_TEST2']);

        $ingestion = app(ReportIngestionService::class);
        foreach ([['4400000001', 'coma_TEST1'], ['4400000002', 'coma_TEST2']] as [$mid, $account]) {
            $ingestion->ingest($cardaq, $this->cardaqCsv($mid), CarbonImmutable::parse('2026-09-15'));
            $ingestion->ingest($corefy, $this->corefyCsv($account), CarbonImmutable::parse('2026-09-15'));
        }
    }

    private function portalUser(Merchant $merchant, array $overrides = []): User
    {
        return User::factory()->create(['role' => UserRole::Merchant, 'company_id' => $merchant->company_id, ...$overrides]);
    }

    public function test_after_login_everyone_lands_in_their_own_area()
    {
        $user = $this->portalUser($this->ours);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('portal.dashboard'));
        $this->actingAs($user)->get(route('admin.dashboard'))->assertRedirect(route('portal.dashboard'));
        $this->actingAs($this->admin)->get('/dashboard')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($this->admin)->get(route('portal.dashboard'))->assertRedirect(route('admin.dashboard'));

        $this->actingAs($this->portalUser($this->ours, ['email' => 'off@x.test', 'is_active' => false]))
            ->get(route('portal.dashboard'))->assertForbidden();
    }

    public function test_the_portal_shows_only_the_companys_merchants_and_no_internal_numbers()
    {
        $user = $this->portalUser($this->ours);

        $this->actingAs($user)->get(route('portal.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portal/dashboard')
                ->where('company', 'Our Co')
                ->has('merchants', 1)
                ->where('overview.payout.0', ['currency' => 'EUR', 'amount' => '273.58'])
                ->where('overview.unpaid.0', ['currency' => 'EUR', 'amount' => '273.58'])
                ->has('recentReports', 1)
                ->missing('recentReports.0.net_profit')
                ->missing('recentReports.0.total_provider_cost'));

        $this->actingAs($user)->get(route('portal.reports'))
            ->assertInertia(fn (Assert $page) => $page->component('portal/reports')->has('reports.data', 1)->where('reports.data.0.merchant', 'Our Shop'));

        // Someone else's report is not reachable, even by id.
        $theirs = DailyReportTask::query()->where('merchant_id', $this->theirs->id)->sole();
        $ours = DailyReportTask::query()->where('merchant_id', $this->ours->id)->sole();
        $this->actingAs($user)->get(route('portal.reports.download', [$theirs, 'pdf']))->assertNotFound();
        $this->actingAs($user)->get(route('portal.reports.download', [$ours, 'pdf']))->assertOk();
    }

    public function test_settlements_drafts_are_hidden_approved_and_paid_are_shown()
    {
        $user = $this->portalUser($this->ours);
        $this->ours->wallets()->create(['type' => 'provider_inflow', 'currency' => 'USDC', 'network' => 'TRC20', 'address' => 'TXours']);
        $service = app(SettlementService::class);
        $draft = $service->createDraft($this->ours, $this->admin);

        $this->actingAs($user)->get(route('portal.settlements'))->assertInertia(fn (Assert $page) => $page->has('settlements.data', 0));
        $this->actingAs($user)->get(route('portal.settlements.pdf', $draft))->assertNotFound();

        $service->markSettled($service->approve($draft, $this->admin), $this->admin, 'abc123');
        $this->actingAs($user)->get(route('portal.settlements'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('settlements.data.0.status_label', 'Paid')
                ->where('settlements.data.0.explorer_url', 'https://tronscan.org/#/transaction/abc123'));
        $this->actingAs($user)->get(route('portal.settlements.pdf', $draft))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($this->portalUser($this->theirs, ['email' => 'them@x.test']))
            ->get(route('portal.settlements.pdf', $draft))->assertNotFound();
    }

    public function test_admins_give_and_take_portal_access_per_company()
    {
        $this->actingAs($this->admin)->post(route('admin.merchants.portal-users.store', $this->ours), [
            'name' => 'Ann', 'email' => 'ann@ours.test', 'password' => 'Secret-pass-123',
        ])->assertSessionHasNoErrors();

        $ann = User::query()->where('email', 'ann@ours.test')->sole();
        $this->assertSame([UserRole::Merchant, $this->ours->company_id, true], [$ann->role, $ann->company_id, $ann->is_active]);

        $this->actingAs($this->admin)->get(route('admin.merchants.show', $this->ours))
            ->assertInertia(fn (Assert $page) => $page
                ->where('portalUsers.0.email', 'ann@ours.test')
                ->has('reports', 1)
                ->where('overview.unpaid.0.amount', '273.58'));

        $this->actingAs($this->admin)->put(route('admin.merchants.portal-users.update', [$this->ours, $ann]), [
            'name' => 'Ann B', 'email' => 'ann@ours.test', 'password' => '', 'is_active' => false,
        ])->assertSessionHasNoErrors();
        $this->assertFalse($ann->fresh()->is_active);

        // Not through another company's merchant, and never a staff account.
        $this->actingAs($this->admin)->delete(route('admin.merchants.portal-users.destroy', [$this->theirs, $ann]))->assertNotFound();
        $this->actingAs($this->admin)->delete(route('admin.merchants.portal-users.destroy', [$this->ours, $this->admin]))->assertNotFound();

        $this->actingAs($this->admin)->delete(route('admin.merchants.portal-users.destroy', [$this->ours, $ann]))->assertRedirect();
        $this->assertNull($ann->fresh());

        $loner = $this->merchantWithTariff(['company_id' => null]);
        $this->actingAs($this->admin)->post(route('admin.merchants.portal-users.store', $loner), [
            'name' => 'X', 'email' => 'x@x.test', 'password' => 'Secret-pass-123',
        ])->assertSessionHasErrors('portal');
    }
}

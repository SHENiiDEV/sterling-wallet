<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Mail\ClientDailyReportMail;
use App\Models\Client;
use App\Models\Company;
use App\Models\DailyReportTask;
use App\Models\User;
use App\Reports\Generation\ClientDailyStatement;
use App\Reports\Ingestion\ReportIngestionService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class ClientTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        $this->admin = User::factory()->create();
    }

    /**
     * Two companies of one client, one MID each, both with the worked-example day.
     */
    private function clientWithTwoCompanies(): Client
    {
        $client = Client::query()->create(['name' => 'Luneholm group', 'invoice_email' => 'finance@luneholm.test']);
        $corefy = $this->corefy();
        $bank = $this->cardaq();
        $ingestion = app(ReportIngestionService::class);
        $day = CarbonImmutable::parse('2026-09-15');

        foreach ([['LUNEHOLM LTD', '4400000001', 'coma_TEST1'], ['DOMESTIC DREAMS LTD', '4400000002', 'coma_TEST2']] as [$name, $mid, $account]) {
            $company = Company::factory()->create(['name' => $name, 'client_id' => $client->id]);
            $merchant = $this->merchantWithTariff(['company_id' => $company->id, 'name' => $name]);
            $this->mid($merchant, $bank, $corefy, ['mid' => $mid, 'gate_mid' => $account]);
            $ingestion->ingest($bank, $this->cardaqCsv($mid), $day);
            $ingestion->ingest($corefy, $this->corefyCsv($account), $day);
        }

        return $client;
    }

    public function test_client_is_created_and_a_company_is_assigned_to_it()
    {
        $this->actingAs($this->admin)->post(route('admin.clients.store'), ['name' => 'Luneholm group', 'invoice_email' => 'a@b.test'])->assertSessionHasNoErrors();
        $client = Client::query()->sole();
        $company = Company::factory()->create();

        $this->actingAs($this->admin)->put(route('admin.companies.update', $company), ['name' => $company->name, 'client_id' => $client->id])->assertSessionHasNoErrors();

        $this->assertSame($client->id, $company->fresh()->client_id);
        $this->actingAs($this->admin)->get(route('admin.clients.index'))->assertOk();
    }

    public function test_deleting_a_client_keeps_its_companies()
    {
        $client = Client::query()->create(['name' => 'Gone']);
        $company = Company::factory()->create(['client_id' => $client->id]);

        $this->actingAs($this->admin)->delete(route('admin.clients.destroy', $client))->assertRedirect();

        $this->assertNull($company->fresh()->client_id);
    }

    public function test_daily_report_adds_up_both_companies()
    {
        $client = $this->clientWithTwoCompanies();

        $data = app(ClientDailyStatement::class)->data($client, CarbonImmutable::parse('2026-09-15'));

        $this->assertSame(['DOMESTIC DREAMS LTD', 'LUNEHOLM LTD'], array_keys($data['companies']));
        $this->assertSame(6, $data['totals']['EUR']['sales']);
        $this->assertTrue($data['totals']['EUR']['turnover']->isEqualTo('700.00'));
        $this->assertTrue($data['totals']['EUR']['payout']->isEqualTo(BigDecimal::of('273.58')->multipliedBy(2)));
        $this->assertSame([], $data['pending']);
    }

    public function test_report_downloads_and_is_mailed_to_the_client()
    {
        $client = $this->clientWithTwoCompanies();

        $pdf = $this->actingAs($this->admin)->get(route('admin.clients.report', ['client' => $client, 'date' => '2026-09-15']));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($this->admin)->get(route('admin.clients.report', ['client' => $client, 'date' => '2026-09-15', 'format' => 'xlsx']))
            ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->actingAs($this->admin)->post(route('admin.clients.send-report', $client), ['date' => '2026-09-15'])->assertSessionHasNoErrors();
        Mail::assertQueued(ClientDailyReportMail::class, fn ($mail) => $mail->hasTo('finance@luneholm.test'));
    }

    public function test_a_report_not_ready_is_listed_as_pending()
    {
        $client = $this->clientWithTwoCompanies();
        DailyReportTask::query()->where('merchant_id', $client->merchants()->orderBy('merchants.id')->value('merchants.id'))->update(['status' => 'partial']);

        $data = app(ClientDailyStatement::class)->data($client, CarbonImmutable::parse('2026-09-15'));

        $this->assertCount(1, $data['pending']);
        $this->assertSame(3, $data['totals']['EUR']['sales']);
    }

    public function test_the_report_can_be_limited_to_one_company()
    {
        $client = $this->clientWithTwoCompanies();
        $company = $client->companies()->where('name', 'LUNEHOLM LTD')->firstOrFail();

        $data = app(ClientDailyStatement::class)->data($client, CarbonImmutable::parse('2026-09-15'), $company->id);

        $this->assertSame(['LUNEHOLM LTD'], array_keys($data['companies']));
        $this->assertSame(3, $data['totals']['EUR']['sales']);

        $pdf = $this->actingAs($this->admin)->get(route('admin.clients.report', ['client' => $client, 'date' => '2026-09-15', 'company' => $company->id]));
        $pdf->assertOk();
        $this->assertStringContainsString('LUNEHOLM_LTD', (string) $pdf->headers->get('Content-Disposition'));

        // A company of another client is not available.
        $stranger = Company::factory()->create();
        $this->actingAs($this->admin)->get(route('admin.clients.report', ['client' => $client, 'date' => '2026-09-15', 'company' => $stranger->id]))->assertNotFound();
    }

    public function test_the_client_page_lists_days_with_each_company()
    {
        $client = $this->clientWithTwoCompanies();

        $this->actingAs($this->admin)->get(route('admin.clients.show', ['client' => $client, 'month' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/clients/show')
                ->has('companies', 2)
                ->where('month', '2026-09')
                ->has('days', 1)
                ->where('days.0.date', '2026-09-15')
                ->where('days.0.completed', 2)
                ->has('days.0.companies', 2));
    }

    public function test_a_client_login_sees_all_companies_and_downloads_the_statement()
    {
        $client = $this->clientWithTwoCompanies();

        $this->actingAs($this->admin)->post(route('admin.clients.portal-users.store', $client), [
            'name' => 'Client Owner', 'email' => 'owner@luneholm.test', 'password' => 'a-long-password-1',
        ])->assertSessionHasNoErrors();
        $owner = User::query()->where('email', 'owner@luneholm.test')->firstOrFail();
        $this->assertSame([$client->id, null, UserRole::Merchant], [$owner->client_id, $owner->company_id, $owner->role]);

        $this->actingAs($owner)->get(route('portal.reports', ['month' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portal/reports')
                ->where('portal.root', 'Luneholm group')
                ->has('portal.companies', 2)
                ->where('clientDays.month', '2026-09')
                ->has('clientDays.days', 1)
                ->has('clientDays.days.0.companies', 2));

        $all = $this->actingAs($owner)->get(route('portal.reports.client', ['date' => '2026-09-15']));
        $all->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $company = $client->companies()->where('name', 'LUNEHOLM LTD')->firstOrFail();
        $this->actingAs($owner)->get(route('portal.reports.client', ['date' => '2026-09-15', 'format' => 'xlsx', 'company' => $company->id]))->assertOk();

        // Another client's company is not reachable.
        $stranger = Company::factory()->create();
        $this->actingAs($owner)->get(route('portal.reports.client', ['date' => '2026-09-15', 'company' => $stranger->id]))->assertNotFound();
    }

    public function test_a_company_login_gets_no_client_statement()
    {
        $client = $this->clientWithTwoCompanies();
        $company = $client->companies()->firstOrFail();
        $user = User::factory()->create(['role' => UserRole::Merchant, 'company_id' => $company->id]);

        $this->actingAs($user)->get(route('portal.reports'))->assertOk()->assertInertia(fn ($page) => $page->where('clientDays', null));
        $this->actingAs($user)->get(route('portal.reports.client', ['date' => '2026-09-15']))->assertNotFound();
    }

    public function test_the_report_shows_the_invoice_details_and_the_payout_calculation()
    {
        $client = $this->clientWithTwoCompanies();
        $client->update(['invoice_name' => 'Luneholm Group Ltd', 'invoice_address' => "1 High Street\nRiga, LV-1010"]);
        $this->assertSame('Luneholm Group Ltd', $client->invoiceName());

        $data = app(ClientDailyStatement::class)->data($client->fresh(), CarbonImmutable::parse('2026-09-15'));

        // The calculation ends in the same payout as the table.
        $steps = $data['calc']['total']['EUR'];
        $payout = collect($steps)->firstWhere('kind', 'total')['amount'];
        $this->assertTrue($payout->isEqualTo($data['totals']['EUR']['payout']));
        $this->assertSame('Gross sales', $steps[0]['label']);
        $this->assertSame(['DOMESTIC DREAMS LTD', 'LUNEHOLM LTD'], array_keys($data['calc']['companies']));

        $this->actingAs($this->admin)->get(route('admin.clients.report', ['client' => $client, 'date' => '2026-09-15']))->assertOk();
        $this->actingAs($this->admin)->put(route('admin.clients.update', $client), ['name' => 'Internal', 'invoice_name' => 'Legal Name', 'invoice_address' => 'Somewhere 1'])->assertSessionHasNoErrors();
        $this->assertSame(['Internal', 'Legal Name'], [$client->fresh()->name, $client->fresh()->invoiceName()]);
    }
}

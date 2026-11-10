<?php

namespace Tests\Feature\Admin;

use App\Enums\OperationType;
use App\Enums\ProviderType;
use App\Enums\UserRole;
use App\Mail\ClientDailyReportMail;
use App\Models\Client;
use App\Models\Company;
use App\Models\DailyReportTask;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\User;
use App\Reports\Generation\ClientDailyStatement;
use App\Reports\Generation\ClientTrafficReport;
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

    public function test_a_period_statement_adds_the_days_up_per_mid_and_lists_missing_days()
    {
        $client = $this->clientWithTwoCompanies();
        // The next day: the same figures again (the fixture files reuse their ids, so copy the finished reports).
        foreach (DailyReportTask::query()->where('report_date', '2026-09-15')->get() as $task) {
            $copy = $task->replicate();
            $copy->report_date = '2026-09-16';
            $copy->period_from = '2026-09-16';
            $copy->period_to = '2026-09-16';
            $copy->save();
        }

        $data = app(ClientDailyStatement::class)->data($client, CarbonImmutable::parse('2026-09-15'), null, CarbonImmutable::parse('2026-09-16'));

        $this->assertTrue($data['isPeriod']);
        $this->assertSame(12, $data['totals']['EUR']['sales']);
        $this->assertTrue($data['totals']['EUR']['payout']->isEqualTo(BigDecimal::of('273.58')->multipliedBy(4)));
        $this->assertSame(['2026-09-15', '2026-09-16'], array_keys($data['days']));
        // Each MID is one line, its two days added up.
        $this->assertCount(1, $data['companies']['LUNEHOLM LTD']['rows']);
        $this->assertSame(6, $data['companies']['LUNEHOLM LTD']['rows'][0]['sales']);
        $this->assertSame([], $data['missing']);

        // Thursday has no report; a period ending on Friday leaves the Friday–Sunday report (dated Sunday) out.
        $later = app(ClientDailyStatement::class)->data($client, CarbonImmutable::parse('2026-09-15'), null, CarbonImmutable::parse('2026-09-18'));
        $this->assertSame(['4400000001' => ['2026-09-17'], '4400000002' => ['2026-09-17']], $later['missing']);

        $pdf = $this->actingAs($this->admin)->get(route('admin.clients.report', ['client' => $client, 'date' => '2026-09-15', 'to' => '2026-09-16']));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('report_', (string) $pdf->headers->get('Content-Disposition'));
        $this->actingAs($this->admin)->get(route('admin.clients.report', ['client' => $client, 'date' => '2026-09-15', 'to' => '2026-09-16', 'format' => 'xlsx']))->assertOk();
        // The period must not run backwards or past a year.
        $this->actingAs($this->admin)->get(route('admin.clients.report', ['client' => $client, 'date' => '2026-09-15', 'to' => '2026-09-14']))->assertSessionHasErrors('to');
        $this->actingAs($this->admin)->get(route('admin.clients.report', ['client' => $client, 'date' => '2026-09-15', 'to' => '2028-01-01']))->assertSessionHasErrors('to');
    }

    public function test_the_traffic_report_counts_attempts_by_reason_and_leaves_routing_errors_out()
    {
        $client = $this->clientWithTwoCompanies();
        $mid = MerchantMid::query()->where('mid', '4400000001')->firstOrFail();
        // A routing error never reached a bank: it is not an attempt of the client.
        MerchantOperation::query()->create([
            'merchant_id' => $mid->merchant_id, 'merchant_mid_id' => $mid->id, 'provider_id' => $mid->gate_provider_id, 'role' => ProviderType::Gate,
            'payment_id' => 'cpi_ROUTING', 'operation_type' => OperationType::Decline, 'resolution' => 'ROUTING_ERROR', 'amount' => '20.00', 'currency' => 'EUR',
            'ips' => 'visa', 'issuer_country' => 'DE', 'report_date' => '2026-09-15', 'transaction_at' => CarbonImmutable::parse('2026-09-15 08:00:00', 'UTC'),
        ]);

        $data = app(ClientTrafficReport::class)->data($client, CarbonImmutable::parse('2026-09-15'), CarbonImmutable::parse('2026-09-21'));

        // Two terminals, each 3 successful and 2 declined payments.
        $this->assertSame([10, 6, 4, 60.0, 1], [$data['totals']['attempts'], $data['totals']['successful'], $data['totals']['declined'], $data['totals']['rate'], $data['routingErrors']]);
        $this->assertTrue($data['totals']['amount']['EUR']->isEqualTo('700.00'));
        $this->assertSame(['2026-09-15'], array_keys($data['days']));
        $this->assertSame([['insufficient funds', 2], ['do not honor', 2]], collect($data['reasons'])->map(fn ($r) => [$r['reason'], $r['count']])->sortBy(0, SORT_DESC)->values()->all());
        $this->assertCount(2, $data['mids']);

        $pdf = $this->actingAs($this->admin)->get(route('admin.clients.traffic', ['client' => $client, 'date' => '2026-09-15', 'to' => '2026-09-21']));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('traffic_', (string) $pdf->headers->get('Content-Disposition'));
        $this->actingAs($this->admin)->get(route('admin.clients.traffic', ['client' => $client, 'date' => '2026-09-15', 'to' => '2026-09-21', 'format' => 'xlsx']))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.clients.traffic', ['client' => $client, 'date' => '2026-09-15']))->assertSessionHasErrors('to');
        $this->actingAs($this->admin)->get(route('admin.clients.traffic', ['client' => $client, 'date' => '2026-01-01', 'to' => '2026-09-21']))->assertSessionHasErrors('to');
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

    public function test_two_sites_of_one_company_are_told_apart_in_the_report()
    {
        $client = Client::query()->create(['name' => 'Luneholm group']);
        $company = Company::factory()->create(['name' => 'DOMESTIC DREAMS LIMITED', 'client_id' => $client->id]);
        $corefy = $this->corefy();
        $bank = $this->cardaq();
        $ingestion = app(ReportIngestionService::class);
        $day = CarbonImmutable::parse('2026-09-15');

        foreach ([['streetforge.co.uk', '4400000002', 'coma_TEST2'], ['makemy-cv.co.uk', '4400000001', 'coma_TEST1']] as [$site, $mid, $account]) {
            $merchant = $this->merchantWithTariff(['company_id' => $company->id, 'name' => 'DOMESTIC DREAMS LIMITED · '.$site, 'website' => $site]);
            $this->mid($merchant, $bank, $corefy, ['mid' => $mid, 'gate_mid' => $account]);
            $ingestion->ingest($bank, $this->cardaqCsv($mid), $day);
            $ingestion->ingest($corefy, $this->corefyCsv($account), $day);
        }

        $rows = app(ClientDailyStatement::class)->data($client, $day)['companies']['DOMESTIC DREAMS LIMITED']['rows'];

        $this->assertSame(['makemy-cv.co.uk', 'streetforge.co.uk'], array_column($rows, 'website'));
        $this->actingAs($this->admin)->get(route('admin.clients.report', ['client' => $client, 'date' => '2026-09-15']))->assertOk();
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Enums\ProviderType;
use App\Enums\ReportStatus;
use App\Models\DailyReportSource;
use App\Models\DailyReportTask;
use App\Models\MerchantMid;
use App\Models\MerchantOperation;
use App\Models\Provider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class VolumesAndSourcesTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private User $admin;

    private Provider $bank;

    private Provider $gate;

    private MerchantMid $mid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Europe/Riga'));
        $this->admin = User::factory()->superAdmin()->create();
        $this->bank = $this->cardaq();
        $this->gate = $this->corefy();
        $this->mid = $this->mid($this->merchantWithTariff(['name' => 'Volume Shop']), $this->bank, $this->gate, ['processing_limit' => 1000]);
    }

    private function gateOperation(string $payment, string $type, string $amount, string $day, string $ips = 'visa'): void
    {
        MerchantOperation::query()->create([
            'merchant_id' => $this->mid->merchant_id, 'merchant_mid_id' => $this->mid->id, 'provider_id' => $this->gate->id,
            'role' => ProviderType::Gate, 'mid' => $this->mid->mid, 'payment_id' => $payment, 'operation_type' => $type,
            'trn_type' => $type, 'ips' => $ips, 'amount' => $amount, 'currency' => 'EUR',
            'transaction_at' => $day.' 10:00:00', 'report_date' => $day,
        ]);
    }

    public function test_volumes_count_successful_gateway_sales_per_terminal_and_day()
    {
        $this->gateOperation('G1', 'sale', '100.00', '2026-10-18');
        $this->gateOperation('G2', 'sale', '200.00', '2026-10-18');
        $this->gateOperation('G3', 'decline', '50.00', '2026-10-19');
        $this->gateOperation('G4', 'refund', '-30.00', '2026-10-19');
        $this->gateOperation('G5', 'sale', '999.00', '2026-09-30');

        $near = fn (float $expected) => fn ($value) => abs($value - $expected) < 0.01;

        $this->actingAs($this->admin)->get(route('admin.volumes.index', ['view' => 'month']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('month', '2026-10')
                ->where('rows.0.mid', $this->mid->mid)
                ->where('rows.0.approved', 2)
                ->where('rows.0.declined', 1)
                ->where('rows.0.volume', $near(300))
                ->where('rows.0.used_percent', $near(30))
                ->where('totals.0.currency', 'EUR'));

        $this->get(route('admin.volumes.index', ['view' => 'month', 'month' => '2026-10', 'mid' => $this->mid->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('selected.days', 31)
                ->where('selected.days.17.approved', 2)
                ->where('selected.days.17.volume', $near(300))
                ->where('selected.days.18.declined', 1));

        // Another month has its own figures.
        $this->get(route('admin.volumes.index', ['view' => 'month', 'month' => '2026-09']))
            ->assertInertia(fn (Assert $page) => $page->where('rows.0.volume', $near(999)));
    }

    public function test_a_weekend_bundle_report_is_split_into_the_days_the_payments_were_made()
    {
        // One Fri–Sun report: every row carries the Sunday report date, the payments happened on three days (gateway time is Riga).
        foreach ([
            ['F1', '100.00', '2026-10-16 10:00:00'],
            ['S1', '200.00', '2026-10-17 10:00:00'],
            ['S2', '50.00', '2026-10-17 22:30:00'], // 01:30 on Sunday in Riga
            ['U1', '400.00', '2026-10-18 10:00:00'],
        ] as [$payment, $amount, $at]) {
            MerchantOperation::query()->create([
                'merchant_id' => $this->mid->merchant_id, 'merchant_mid_id' => $this->mid->id, 'provider_id' => $this->gate->id,
                'role' => ProviderType::Gate, 'mid' => $this->mid->mid, 'payment_id' => $payment, 'operation_type' => 'sale',
                'trn_type' => 'sale', 'ips' => 'visa', 'amount' => $amount, 'currency' => 'EUR',
                'transaction_at' => $at, 'report_date' => '2026-10-18',
            ]);
        }

        $volume = fn (string $day) => $this->get(route('admin.volumes.index', ['day' => $day]))->viewData('page')['props']['rows'][0]['volume'];

        $this->actingAs($this->admin);
        $this->assertEqualsWithDelta(100, $volume('2026-10-16'), 0.01);
        $this->assertEqualsWithDelta(200, $volume('2026-10-17'), 0.01);
        $this->assertEqualsWithDelta(450, $volume('2026-10-18'), 0.01);
    }

    public function test_a_single_day_is_compared_with_the_daily_norm()
    {
        $this->mid->update(['daily_norm' => 150]);
        $this->gateOperation('G1', 'sale', '100.00', '2026-10-18');
        $this->gateOperation('G2', 'sale', '200.00', '2026-10-18');
        $this->gateOperation('G3', 'sale', '60.00', '2026-10-19');
        $near = fn (float $expected) => fn ($value) => abs($value - $expected) < 0.01;

        // The default is Today (20 Oct): nothing yet, the whole norm is missing.
        $this->actingAs($this->admin)->get(route('admin.volumes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('view', 'day')
                ->where('day', '2026-10-20')
                ->where('isToday', true)
                ->where('shortcuts.0.label', 'Today')
                ->where('shortcuts.1.label', 'Yesterday')
                ->where('rows.0.approved', 0)
                ->where('rows.0.shortfall', $near(150)));

        $this->get(route('admin.volumes.index', ['day' => '2026-10-18', 'mid' => $this->mid->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('isToday', false)
                ->where('rows.0.volume', $near(300))
                ->where('rows.0.norm_percent', $near(200))
                ->where('rows.0.shortfall', $near(0))
                ->has('selected.days', 14)
                ->where('selected.days.13.volume', $near(300))
                ->where('selected.days.12.norm_percent', $near(0)));

        $this->get(route('admin.volumes.index', ['day' => '2026-10-19']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.0.volume', $near(60))
                ->where('rows.0.norm_percent', $near(40))
                ->where('rows.0.shortfall', $near(90)));
    }

    public function test_the_plan_says_how_much_to_give_per_day_to_fill_each_scheme_limit()
    {
        // One terminal, 200k a month on Visa and 200k on Mastercard (Madfin style).
        $this->mid->update(['processing_limit_visa' => 200000, 'processing_limit_mastercard' => 200000]);
        $this->gateOperation('V1', 'sale', '50000.00', '2026-10-10', 'visa');
        $this->gateOperation('M1', 'sale', '20000.00', '2026-10-12', 'mastercard');
        $this->gateOperation('M2', 'decline', '99999.00', '2026-10-12', 'mastercard');
        $this->gateOperation('V0', 'sale', '7000.00', '2026-09-28', 'visa');
        // Today (20 Oct) is not counted: its report arrives tomorrow.
        $this->gateOperation('VT', 'sale', '1000.00', '2026-10-20', 'visa');
        $near = fn (float $expected) => fn ($value) => abs($value - $expected) < 0.01;

        // 20 Oct: 11 days after today (21 to 31); the daily amount goes down to a multiple of 500.
        $this->actingAs($this->admin)->get(route('admin.volumes.index', ['view' => 'plan']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('plan.days_left', 11)
                ->where('plan.terminals.0.mid', $this->mid->mid)
                ->where('plan.terminals.0.buckets.0.scheme', 'visa')
                ->where('plan.terminals.0.buckets.0.processed', $near(50000))
                ->where('plan.terminals.0.buckets.0.left', $near(150000))
                ->where('plan.terminals.0.buckets.0.needed_per_day', $near(13636.36))
                ->where('plan.terminals.0.buckets.0.needed_rounded', 13500)
                ->where('plan.terminals.0.buckets.1.scheme', 'mastercard')
                ->where('plan.terminals.0.buckets.1.left', $near(180000))
                ->where('plan.terminals.0.buckets.1.needed_rounded', 16000));

        // Without scheme limits the single processing limit counts every card.
        $this->mid->update(['processing_limit_visa' => null, 'processing_limit_mastercard' => null, 'processing_limit' => 100000]);
        $this->get(route('admin.volumes.index', ['view' => 'plan']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('plan.terminals.0.buckets.0.scheme', 'all')
                ->where('plan.terminals.0.buckets.0.processed', $near(70000))
                ->where('plan.terminals.0.buckets.0.needed_rounded', 2500));
    }

    public function test_the_raw_provider_files_of_a_report_can_be_downloaded()
    {
        $disk = config('sterling.reports.disk');
        Storage::fake($disk);
        Storage::disk($disk)->put('reports/raw/cardaq/2026-10-18/101010_bank.csv', "a;b\n1;2\n");

        $report = DailyReportTask::query()->create([
            'merchant_id' => $this->mid->merchant_id, 'merchant_mid_id' => $this->mid->id, 'report_date' => '2026-10-18',
            'period_from' => '2026-10-18', 'period_to' => '2026-10-18', 'currency' => 'EUR', 'status' => ReportStatus::Completed,
        ]);
        $source = DailyReportSource::query()->create([
            'daily_report_task_id' => $report->id, 'provider_id' => $this->bank->id, 'role' => ProviderType::Bank,
            'file_path' => 'reports/raw/cardaq/2026-10-18/101010_bank.csv', 'received_at' => now(), 'rows_count' => 1,
        ]);
        $other = DailyReportTask::query()->create([
            'merchant_id' => $this->mid->merchant_id, 'merchant_mid_id' => $this->mid->id, 'report_date' => '2026-10-17',
            'period_from' => '2026-10-17', 'period_to' => '2026-10-17', 'currency' => 'EUR', 'status' => ReportStatus::Completed,
        ]);

        $this->actingAs($this->admin)->get(route('admin.reports.show', $report))
            ->assertInertia(fn (Assert $page) => $page->where('sources.0.has_file', true));

        $response = $this->get(route('admin.reports.source', [$report, $source]))->assertOk();
        $this->assertSame("a;b\n1;2\n", $response->streamedContent());
        $this->assertStringContainsString('cardaq_bank_2026-10-18.csv', $response->headers->get('content-disposition'));

        // A file belongs to its own report only.
        $this->get(route('admin.reports.source', [$other, $source]))->assertNotFound();
    }
}

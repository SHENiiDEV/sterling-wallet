<?php

namespace Tests\Feature\Settlements;

use App\Enums\ReportStatus;
use App\Models\BankHoliday;
use App\Models\DailyReportTask;
use App\Models\FxRate;
use App\Models\Merchant;
use App\Models\MerchantMid;
use App\Models\Settlement;
use App\Models\User;
use App\Settlements\DueSettlements;
use App\Settlements\SettlementSchedule;
use App\Settlements\SettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

/**
 * Daily T+3, counted in business days from the first day of the report's period:
 * Mon → Thu, Tue → Fri, Wed → Mon, Thu → Tue, Fri–Sun → Wed.
 * 2026-10-19 is a Monday.
 */
class SettlementScheduleTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private User $admin;

    private Merchant $merchant;

    private MerchantMid $mid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'Europe/Riga'));
        $this->admin = User::factory()->superAdmin()->create();
        $this->merchant = $this->merchantWithTariff(['name' => 'Scheduled Shop', 'settlement_lag_days' => 3]);
        $this->mid = $this->mid($this->merchant, $this->cardaq(), $this->corefy());
        $this->merchant->wallets()->create(['type' => 'provider_inflow', 'currency' => 'USDC', 'network' => 'TRC20', 'address' => 'TXabc']);
        FxRate::query()->create(['rate_date' => '2026-09-01', 'base' => 'EUR', 'quote' => 'USDC', 'rate' => '1.10']);
    }

    private function report(string $from, ?string $to = null): DailyReportTask
    {
        return DailyReportTask::query()->create([
            'merchant_id' => $this->merchant->id, 'merchant_mid_id' => $this->mid->id, 'report_date' => $to ?? $from,
            'period_from' => $from, 'period_to' => $to ?? $from, 'currency' => 'EUR', 'status' => ReportStatus::Completed,
            'turnover' => 100, 'net_payout' => 90,
        ]);
    }

    public function test_t_plus_3_counts_business_days_and_the_weekend_bundle_counts_from_friday()
    {
        $schedule = app(SettlementSchedule::class);
        $due = fn (string $from, ?string $to = null) => $schedule->dueDate($this->report($from, $to))->toDateString();

        $this->assertSame('2026-10-22', $due('2026-10-19')); // Mon → Thu
        $this->assertSame('2026-10-23', $due('2026-10-20')); // Tue → Fri
        $this->assertSame('2026-10-26', $due('2026-10-21')); // Wed → Mon
        $this->assertSame('2026-10-27', $due('2026-10-22')); // Thu → Tue
        $this->assertSame('2026-10-21', $due('2026-10-16', '2026-10-18')); // Fri–Sun → Wed
    }

    public function test_bank_holidays_are_skipped_like_weekends()
    {
        BankHoliday::query()->create(['date' => '2026-10-26', 'name' => 'Holiday']);

        // Wed 21 → Thu, Fri, (Mon is closed) Tue 27.
        $this->assertSame('2026-10-27', app(SettlementSchedule::class)->dueDate($this->report('2026-10-21'))->toDateString());
    }

    public function test_the_state_tells_what_is_due_today_what_is_late_and_what_is_early()
    {
        $schedule = app(SettlementSchedule::class);

        $this->assertSame(SettlementSchedule::OVERDUE, $schedule->state($this->report('2026-10-16', '2026-10-18'))); // due Wed 21
        $this->assertSame(SettlementSchedule::DUE, $schedule->state($this->report('2026-10-19')));                    // due Thu 22 = today
        $this->assertSame(SettlementSchedule::NOT_DUE, $schedule->state($this->report('2026-10-20')));                // due Fri 23

        $this->merchant->update(['settlement_lag_days' => null]);
        $this->assertNull($schedule->state($this->report('2026-10-13')->fresh()));
    }

    public function test_a_due_draft_leaves_early_reports_out_and_approving_one_needs_confirmation()
    {
        $this->report('2026-10-19');
        $early = $this->report('2026-10-20');
        $service = app(SettlementService::class);

        $dueOnly = $service->createDraft($this->merchant, $this->admin, dueOnly: true);
        $this->assertSame(1, $dueOnly->lines()->where('type', 'report')->count());
        $this->assertSame(0, $dueOnly->lines()->where('daily_report_task_id', $early->id)->count());

        $everything = $service->createDraft($this->merchant, $this->admin);
        $this->assertSame([$early->id], $service->earlyReports($everything)->pluck('id')->all());

        try {
            $service->approve($everything, $this->admin);
            $this->fail('Approving a not-due report should need confirmation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('early', $e->errors());
        }

        $service->approve($everything, $this->admin, confirmEarly: true);
        $this->assertSame('approved', $everything->fresh()->status->value);

        // A draft with only due reports approves as before.
        $this->assertNotNull($service->approve($dueOnly, $this->admin)->approved_at);
    }

    public function test_the_settlements_page_and_dashboard_show_what_is_due()
    {
        $this->report('2026-10-19'); // due today
        $this->report('2026-10-20'); // due tomorrow

        $this->assertSame(1, app(DueSettlements::class)->merchants()->count());

        $this->actingAs($this->admin)->get(route('admin.settlements.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pending.0.scheduled', true)
                ->where('pending.0.reports', 2)
                ->where('pending.0.due_reports', 1)
                ->where('pending.0.next_due', '2026-10-23'));

        $this->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('reportAlerts.0.key', 'settlements_due')->where('reportAlerts.0.count', 1));

        // The page's draft takes the due report only.
        $this->post(route('admin.settlements.store'), ['merchant' => $this->merchant->public_id])->assertRedirect();
        $this->assertSame(1, Settlement::query()->latest('id')->first()->lines()->count());
    }
}

<?php

namespace Tests\Feature\Reports;

use App\Models\BankHoliday;
use App\Reports\ReportDateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsReportFixtures;
use Tests\TestCase;

class ReportDateResolverTest extends TestCase
{
    use BuildsReportFixtures, RefreshDatabase;

    private function period(string $day): array
    {
        $period = app(ReportDateResolver::class)->periodFor($day);

        return [$period->reportDate->toDateString(), $period->from->toDateString(), $period->to->toDateString()];
    }

    public function test_a_weekday_is_its_own_report()
    {
        // 2026-09-15 is a Tuesday.
        $this->assertSame(['2026-09-15', '2026-09-15', '2026-09-15'], $this->period('2026-09-15'));
    }

    public function test_friday_to_sunday_is_one_sunday_report()
    {
        foreach (['2026-09-11', '2026-09-12', '2026-09-13'] as $day) {
            $this->assertSame(['2026-09-13', '2026-09-11', '2026-09-13'], $this->period($day), $day);
        }
        $this->assertSame(['2026-09-14', '2026-09-14', '2026-09-14'], $this->period('2026-09-14'));
    }

    public function test_a_monday_holiday_joins_the_weekend()
    {
        BankHoliday::query()->create(['date' => '2026-09-14', 'name' => 'Test holiday']);

        foreach (['2026-09-11', '2026-09-13', '2026-09-14'] as $day) {
            $this->assertSame(['2026-09-14', '2026-09-11', '2026-09-14'], $this->period($day), $day);
        }
    }

    public function test_a_midweek_holiday_joins_the_day_before()
    {
        BankHoliday::query()->create(['date' => '2026-09-16', 'name' => 'Wednesday holiday']);

        $this->assertSame(['2026-09-16', '2026-09-15', '2026-09-16'], $this->period('2026-09-15'));
        $this->assertSame(['2026-09-17', '2026-09-17', '2026-09-17'], $this->period('2026-09-17'));
    }

    public function test_ready_periods_respect_the_provider_delay()
    {
        $resolver = app(ReportDateResolver::class);
        $cardaq = $this->cardaq(); // 2 days
        $corefy = $this->corefy(); // 1 day

        // Today Thursday 2026-09-17.
        $dates = fn ($provider) => array_map(
            fn ($p) => $p->reportDate->toDateString(),
            $resolver->readyPeriods($provider, '2026-09-11', '2026-09-17'),
        );

        $this->assertSame(['2026-09-13', '2026-09-14', '2026-09-15'], $dates($cardaq));
        $this->assertSame(['2026-09-13', '2026-09-14', '2026-09-15', '2026-09-16'], $dates($corefy));
    }
}

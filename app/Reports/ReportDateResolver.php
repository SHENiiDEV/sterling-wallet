<?php

namespace App\Reports;

use App\Models\BankHoliday;
use App\Models\Provider;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The one place that knows which report a day belongs to.
 *
 * Banks don't clear on closed days (Saturday, Sunday, bank holidays), so a
 * closed day is reported together with the working day before it and any
 * closed days after it: Fri+Sat+Sun → the Sunday report; a Monday holiday
 * extends that to Fri…Mon → the Monday report.
 */
class ReportDateResolver
{
    /** @var array<string, true>|null */
    private ?array $holidays = null;

    public function periodFor(DateTimeInterface|string $day): ReportPeriod
    {
        $day = $this->day($day);

        $to = $day;
        while ($this->isClosed($to->addDay())) {
            $to = $to->addDay();
        }

        $from = $day;
        while ($this->isClosed($from)) {
            $from = $from->subDay();
        }

        return new ReportPeriod($to, $from, $to);
    }

    /**
     * Normalises a report date to the date the resolver would give it
     * (e.g. a Friday passed as report date becomes that weekend's Sunday).
     */
    public function reportDateFor(DateTimeInterface|string $day): CarbonImmutable
    {
        return $this->periodFor($day)->reportDate;
    }

    /**
     * First day the provider's file for this period can be fetched.
     */
    public function readyOn(Provider $provider, ReportPeriod $period): CarbonImmutable
    {
        return $this->day($period->to)->addDays($provider->report_delay_days);
    }

    /**
     * Report periods in [from, today] whose file the provider should already have.
     *
     * @return list<ReportPeriod>
     */
    public function readyPeriods(Provider $provider, DateTimeInterface|string $from, DateTimeInterface|string|null $today = null): array
    {
        $today = $this->day($today ?? CarbonImmutable::now(config('sterling.timezone'))->format('Y-m-d'));
        $cursor = $this->day($from);
        $periods = [];

        while ($cursor->lessThanOrEqualTo($today)) {
            $period = $this->periodFor($cursor);
            if ($this->readyOn($provider, $period)->lessThanOrEqualTo($today)) {
                $periods[] = $period;
            }
            $cursor = $period->to->addDay();
        }

        return $periods;
    }

    public function isClosed(CarbonImmutable $day): bool
    {
        return $day->isWeekend() || isset($this->holidays()[$day->toDateString()]);
    }

    /**
     * Holidays are few; load them once per resolver instance.
     *
     * @return array<string, true>
     */
    private function holidays(): array
    {
        return $this->holidays ??= BankHoliday::query()
            ->pluck('date')
            ->mapWithKeys(fn ($date) => [CarbonImmutable::parse($date)->toDateString() => true])
            ->all();
    }

    public function flush(): void
    {
        $this->holidays = null;
    }

    /**
     * Calendar days are compared as plain dates: whatever zone a value comes
     * in, only its Y-m-d counts (always materialised in UTC).
     */
    private function day(DateTimeInterface|string $day): CarbonImmutable
    {
        $date = $day instanceof DateTimeInterface ? $day->format('Y-m-d') : CarbonImmutable::parse($day)->format('Y-m-d');

        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
    }
}
